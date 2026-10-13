<?php

namespace App\Http\Controllers;

use App\Models\Central\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            // Percobaan gagal dicatat ke database PUSAT, bukan ke database
            // lokasi. `AuditLog` mengunci koneksinya ke `central` supaya
            // pencatatan ini tetap terjadi meski `database.default` sedang
            // menunjuk database lokasi.
            //
            // Email yang dicatat BUKAN konfirmasi bahwa email itu
            // terdaftar di lokasi mana pun -- baris ini tetap ditulis
            // meski emailnya sama sekali tidak dikenal. Yang berguna dari
            // sini adalah IP dan user-agent-nya.
            $this->audit->record(
                'auth.login_failed',
                description: 'Percobaan login lokasi gagal untuk email `'.$request->email.'`.',
                properties: ['email' => $request->email],
            );

            throw ValidationException::withMessages([
                'email' => ['Cek email atau password.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $this->audit->record(
            'auth.login',
            tenant: $this->currentTenant(),
            subjectType: User::class,
            subjectId: $user->id,
            description: "{$user->name} ({$user->email}) login ke aplikasi lokasi.",
            properties: [
                'email' => $user->email,
                'role' => $user->role,
                // Token name dicatat, token-nya sendiri tidak: siapa pun yang
                // bisa membaca log harus bisa melihat bahwa ada login
                // terjadi -- bukan bisa memakainya.
                'token_name' => 'auth_token',
            ],
            // `$user` eksplisit: `auth()->user()` masih null di titik ini,
            // karena token baru dibuat pada request ini dan Sanctum baru
            // mencarinya pada request berikutnya.
            actor: $user,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'token' => $token,
            ],
        ]);

    }

    public function logout(Request $request)
    {
        $user = $request->user();

        $user->currentAccessToken()->delete();

        $this->audit->record(
            'auth.logout',
            tenant: $this->currentTenant(),
            subjectType: User::class,
            subjectId: $user->id,
            description: "{$user->name} ({$user->email}) logout dari aplikasi lokasi.",
            properties: ['email' => $user->email, 'role' => $user->role],
        );

        return response()->json([
            'success' => true,
            'data' => ['message' => 'Berhasil logout.'],
        ]);
    }

    public function refresh(Request $request)
    {
        $user = $request->user();

        $user->currentAccessToken()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => ['token' => $token],
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load(['customers.ticket.package', 'customers.meterReadings']);

        $customer = $user->customers->first();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'phone' => $user->phone ?? null,
                'avatar_url' => $user->avatar_path ?: null,
                'identity' => $customer ? [
                    'customer_id' => $customer->id,
                    'customer_code' => $customer->customer_code,
                    'ticket' => $customer->ticket,
                ] : null,
            ],
        ]);
    }

    public function updatePassword(Request $request)
    {
        try {
            $request->validate([
                'current_password' => 'required',
                'new_password' => 'required|min:8|confirmed',
            ]);

            $user = $request->user();

            if (! Hash::check($request->current_password, $user->password)) {
                // Percobaan gagal dicatat dengan alasan yang sama: "email dan
                // password lama salah" vs "password lama salah saja" -- bedanya
                // ada pada bentuk respons, bukan isi pesannya, jadi tidak
                // menambah informasi apa pun ke penyerang.
                $this->audit->record(
                    'auth.password_change_failed',
                    tenant: $this->currentTenant(),
                    subjectType: User::class,
                    subjectId: $user->id,
                    description: "{$user->name} gagal mengganti password: password lama salah.",
                    actor: $user,
                );

                return response()->json([
                    'message' => 'Password lama salah',
                ], 422);
            }

            $user->update([
                'password' => Hash::make($request->new_password),
            ]);

            // Password-nya sendiri TIDAK ikut dicatat, hanya fakta bahwa penggantian
            // terjadi. Hash pun tidak dicatat -- kolomnya hanya punya nilai
            // sebelum dan sesudah dalam bentuk `$2y$...`, yang berguna
            // untuk membandingkan tapi tidak untuk di sini.
            $this->audit->record(
                'auth.password_change',
                tenant: $this->currentTenant(),
                subjectType: User::class,
                subjectId: $user->id,
                description: "{$user->name} ({$user->email}) mengganti password-nya sendiri.",
                actor: $user,
            );

            return response()->json([
                'message' => 'Password berhasil diubah',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function updateProfile(Request $request)
    {
        try {
            $user = $request->user();

            $validated = $request->validate([
                'name' => 'required|max:150',
                'email' => [
                    'required',
                    'email',
                    Rule::unique('users')->ignore($user->id),
                ],
            ]);

            // Dicatat setelah update berhasil, dan hanya kalau ada yang benar-benar
            // berubah. `updateProfile` dipanggil setiap kali form di-submit,
            // termasuk saat user menekan Simpan tanpa mengubah apa pun --
            // mencatatnya setiap kali akan membuat daftar audit
            // penuh baris yang isinya "tidak ada yang berubah", dan baris
            // seperti itu justru menutupi baris yang penting.
            $sebelum = $user->only(array_keys($validated));
            $user->update($validated);
            $perubahan = AuditLogger::diff($sebelum, $user->only(array_keys($validated)));

            if ($perubahan !== []) {
                $this->audit->record(
                    'user.profile_update',
                    tenant: $this->currentTenant(),
                    subjectType: User::class,
                    subjectId: $user->id,
                    description: "Profil {$user->name} ({$user->email}) diperbarui ("
                        .implode(', ', array_keys($perubahan)).').',
                    properties: ['changes' => $perubahan],
                );
            }

            return response()->json([
                'message' => 'Profil berhasil diperbarui',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function uploadAvatar(Request $request)
    {
        try {
            $request->validate([
                'avatar' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048',
            ]);

            $user = $request->user();

            // hapus avatar lama
            if ($user->avatar_path && Storage::disk('public')->exists($user->avatar_path)) {
                Storage::disk('public')->delete($user->avatar_path);
            }

            $path = $request->file('avatar')->store('avatars', 'public');

            $user->update([
                'avatar_path' => $path,
            ]);

            return response()->json([
                'data' => [
                    'avatar_url' => $path,
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Lokasi yang sedang aktif, atau null kalau request tidak punya tenant.
     *
     * `resolve()` di-cast karena middleware bisa membiarkan request lewat
     * tanpa tenant -- yang masuk ke sini cuma endpoint yang tidak butuh
     * lokasi. Audit tetap jalan untuk kasus itu, dengan `tenant_id` null.
     */
    private function currentTenant(): ?Tenant
    {
        return app(TenantManager::class)->current();
    }
}
