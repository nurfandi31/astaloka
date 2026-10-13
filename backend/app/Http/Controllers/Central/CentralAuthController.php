<?php

namespace App\Http\Controllers\Central;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Login untuk user yang TIDAK punya lokasi, yaitu superadmin ASTA BRATA.
 *
 * Login user lokasi TIDAK lewat sini -- ia ditangani oleh AuthController yang
 * sudah ada, setelah middleware ResolveTenant mengarahkan database ke lokasi
 * dari subdomain/header. Pemisahan ini menjaga satu perubahan tidak
 * menyentuh logika yang sedang dipakai 1.673 user lokasi.
 */
class CentralAuthController
{
    public function __construct(
        private TenantManager $manager,
        private AuditLogger $audit,
    ) {}

    /**
     * Login superadmin.
     *
     * Token disimpan di database pusat, terpisah dari token lokasi. Jadi
     * revoking token lokasi tidak menyentuh akun pusat, dan sebaliknya.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Selalu baca dari database pusat. Jangan andalkan default koneksi:
        // middleware ResolveTenant mungkin sudah mengarahkan `default` ke
        // database lokasi bila request datang lewat subdomain yang terdaftar.
        $user = DB::connection(config('tenancy.central_connection'))
            ->table('users')
            ->where('email', $request->email)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            // Percobaan gagal juga dicatat. Untuk panel sekelas ini, daftar
            // percobaan login yang gagal adalah bahan deteksi awal --
            // email yang ada di sini BUKAN konfirmasi bahwa email itu
            // terdaftar, tapi IP dan user-agent-nya tetap berguna saat
            // menelusuri percobaan menebak.
            $this->audit->record(
                'central.login_failed',
                description: 'Percobaan login panel pusat gagal untuk email `'
                    .$request->email.'`.',
                properties: ['email' => $request->email],
            );

            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        if ($user->role !== 'superadmin') {
            // Akun ini ada di database pusat tapi bukan superadmin. Balasan
            // disamakan dengan "kredensial salah" supaya presence database
            // pusat tidak bisa dipakai memetakan email yang valid.
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        $model = $this->modelFor($user);

        // Token disimpan di variabel terpisah supaya baris audit tidak pernah
        // memuat string token -- hanya kalau ada permintaan berikutnya yang
        // gagal.
        //
        // `$model` diteruskan eksplisit sebagai aktor. `auth()->user()` masih
        // null di titik ini: Sanctum baru bisa menemukan user dari token
        // ini pada request BERIKUTNYA, jadi tanpanya `central.login` selalu
        // tercatat sebagai `system` -- dan baris yang paling penting
        // justru jadi yang paling tidak berguna.
        $token = $model->createToken('central_auth')->plainTextToken;

        $this->audit->record(
            'central.login',
            subjectType: User::class,
            subjectId: $model->id,
            // `{$model->email}` -- bukan `${$model->email}`. Bentuk kedua
            // dibaca PHP sebagai interpolasi variabel biasa `$model`,
            // lalu sisanya jadi teks, jadi hasilnya 500 dengan
            // "Undefined variable".
            description: "Superadmin {$model->name} ({$model->email}) login ke panel pusat.",
            properties: [
                'email' => $model->email,
                // Token name dicatat, token-nya sendiri tidak. String token
                // di log berarti siapa pun yang bisa baca log bisa mengambil
                // alih akun.
                'token_name' => 'central_auth',
            ],
            actor: $model,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $model->id,
                    'name' => $model->name,
                    'email' => $model->email,
                    'role' => $model->role,
                ],
                'token' => $token,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $user?->currentAccessToken()?->delete();

        $this->audit->record(
            'central.logout',
            subjectType: User::class,
            subjectId: $user?->id,
            description: "Superadmin `{$user?->name}` logout dari panel pusat.",
            properties: ['email' => $user?->email],
        );

        return response()->json([
            'success' => true,
            'data' => ['message' => 'Berhasil logout.'],
        ]);
    }

    /**
     * Buat model User yang sebenarnya di atas baris database pusat.
     *
     * Token Sanctum butuh Eloquent model (bukan stdClass) supaya relasi
     * `tokens()` dan `tokenable` bekerja.
     */
    private function modelFor(object $row): User
    {
        $model = new User;
        $model->setConnection(config('tenancy.central_connection'));
        $model->forceFill([
            'id' => $row->id,
            'name' => $row->name,
            'email' => $row->email,
            'role' => $row->role,
            'password' => $row->password,
        ]);
        $model->exists = true;

        return $model;
    }
}
