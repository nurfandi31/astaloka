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

        /*
         * Email dan password dicek TERPISAH, masing-masing dengan pesan yang
         * ditunjuk ke kolomnya.
         *
         * Dulu keduanya dilemparkan sebagai satu kondisi dengan satu pesan
         * ("Email atau password salah"), yang muncul sebagai popup di atas
         * form. Dua-duanya tidak bagus: popup menutupi kolom yang salah, dan
         * kalimat "atau" menyuruh orang menebak dua-duanya alih-alih
         * memberitahu mana yang perlu diperbaiki.
         *
         * Yang tetap menutup jalan pemetaan daftar akun pusat adalah
         * `throttle:login` dan `throttle:login-ip` di `routes/central.php`.
         * Perbedaan pesannya di sini tidak menambah kecepatan pemetaan: email
         * harus benar agar bisa mencapai cek password, jadi penyerang sudah
         * harus menebak email dengan benar sebelum bisa memakai perbedaan ini
         * untuk memastikan tebakannya.
         */
        if (! $user) {
            $this->catatLoginGagal($request, 'Email tidak terdaftar di panel pusat.');

            throw ValidationException::withMessages([
                'email' => ['Email tidak terdaftar di panel pusat.'],
            ]);
        }

        if (! Hash::check($request->password, $user->password)) {
            $this->catatLoginGagal($request, 'Kata sandi salah.');

            throw ValidationException::withMessages([
                'password' => ['Kata sandi salah.'],
            ]);
        }

        /*
         * Akun yang ada tapi TIDAK boleh masuk. Ini soal status akun, bukan
         * soal kredensial.
         *
         * Dua kondisi di bawah -- bukan superadmin, dan akun dinonaktifkan --
         * sengaja memakai satu kalimat yang sama dan tetap diletakkan di kolom
         * EMAIL, sama seperti sebelumnya. Bedanya dari "kata sandi salah" itu
         * nyata dan tidak boleh dihapus: "akun Anda bukan superadmin" atau
         * "akun dinonaktifkan" hanya bisa muncul kalau kredensialnya sudah
         * benar, jadi keduanya membocorkan apa yang sebenarnya terjadi pada
         * akun itu.
         *
         * Pemeriksaan akun nonaktif diletakkan di sini, bukan cuma di
         * `EnsureSuperadmin`: middleware itu baru berjalan pada request yang
         * sudah punya token, sedangkan keputusan "akun ini mati" harus berlaku
         * sedini mungkin. Kalau hanya di middleware, akun yang sudah
         * dinonaktifkan masih bisa menukar password-nya menjadi token baru.
         */
        if ($user->role !== 'superadmin' || ! $user->is_active) {
            $this->catatLoginGagal($request, 'Akun tidak bisa masuk ke panel pusat.');

            // Kalimatnya sama persis dengan yang dipakai sebelum perubahan ini,
            // dan `CentralSuperadminTest` menjaganya. Jangan diubah menjadi
            // "bukan superadmin" atau "akun dinonaktifkan": dua kalimat itu
            // hanya muncul kalau kredensialnya sudah benar, jadi keduanya
            // memberi tahu penyerang bahwa sebuah email terdaftar sekaligus
            // masih hidup -- informasi yang tidak ada hubsungannya dengan
            // pekerjaan yang sedang dilakukan.
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

    /**
     * Catat percobaan login panel pusat yang gagal, lengkap dengan alasannya.
     *
     * Percobaan gagal juga dicatat. Untuk panel sekelas ini, daftar percobaan
     * login yang gagal adalah bahan deteksi awal -- email yang ada di sini
     * BUKAN konfirmasi bahwa email itu terdaftar, tapi IP dan user-agent-nya
     * tetap berguna saat menelusuri percobaan menebak.
     *
     * Alasan ikut ditulis supaya "gagal" tidak menjadi satu baris yang sama
     * untuk tiga hal berbeda: email salah ketik, password salah, dan akun
     * yang memang tidak boleh masuk. Tanpa itu, satu-satunya cara membedakan
     * ketiganya adalah membandingkan jumlah baris dengan pesan di layar user.
     */
    private function catatLoginGagal(Request $request, string $alasan): void
    {
        $this->audit->record(
            'central.login_failed',
            description: 'Percobaan login panel pusat gagal untuk email `'
                .$request->email.'`: '.$alasan,
            properties: ['email' => $request->email, 'alasan' => $alasan],
        );
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
