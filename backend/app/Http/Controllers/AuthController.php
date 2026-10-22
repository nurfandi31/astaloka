<?php

namespace App\Http\Controllers;

use App\Models\Central\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InvoiceGate;
use App\Services\ReceivableGenerator;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private AuditLogger $audit,
        private InvoiceGate $gate,
        private ReceivableGenerator $piutang,
    ) {}

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            $this->catatLoginGagal($request, 'Email tidak terdaftar di lokasi ini.');

            // Pesan ditunjuk ke kolom email, dan sengaja TIDAK disamakan
            // dengan "kata sandi salah" seperti sebelumnya.
            //
            // Idealnya halaman login tidak memberi tahu apakah sebuah email
            // terdaftar -- cukup satu percobaan untuk memetakan seluruh daftar
            // akun lokasi. Tapi menaruh kesalahannya di bawah input yang tepat
            // jauh lebih berguna daripada satu kalimat generik di popup: orang
            // yang salah ketik alamat emailnya (huruf tertukar, domain berbeda,
            // akun dari lokasi lain) langsung tahu harus memperbaiki yang mana,
            // bukan menebak dua-duanya.
            //
            // Yang tetap menutup jalan pemetaan itu adalah `throttle:login`
            // dan `throttle:login-ip` di route: lima percobaan per menit per
            // kombinasi email+IP, dan dua puluh per menit per IP. Satu email
            // masih bisa ditebak dalam jendela itu, tapi memetakan seluruh
            // daftar butuh waktu jauh lebih lama dari jendela yang tersedia.
            throw ValidationException::withMessages([
                'email' => ['Email tidak terdaftar di lokasi ini.'],
            ]);
        }

        if (! Hash::check($request->password, $user->password)) {
            $this->catatLoginGagal($request, 'Kata sandi salah.');

            throw ValidationException::withMessages([
                'password' => ['Kata sandi salah.'],
            ]);
        }

        /*
         * Tagihan yang belum dilunasi DITOLAK DI SINI, sebelum token terbit.
         *
         * Kenapa bukan membiarkan login lalu memblokir di halamannya saja:
         * memblokir setelah login berarti user sempat masuk ke aplikasi,
         * melihat dashboard kosong, lalu dikick. Itu terasa seperti bug,
         * bukan seperti penagihan -- dan yang lebih buruk, tokennya sudah
         * terbit. Jadi user bisa keluar-masuk terus sambil selalu melihat
         * error 402.
         *
         * Yang dikembalikan 402, bukan 401: password-nya benar, akunnya
         * aktif, yang belum lunas adalah tagihan. 401 akan dibaca frontend
         * sebagai "sesi habis" -- interceptor di utils/axios.js akan
         * menghapus token dan melempar user ke /login, jadi popup tagihan
         * tidak pernah sempat tampil.
         *
         * Pemeriksaan ini memakai `InvoiceGate` yang sama dengan middleware
         * request, jadi tidak mungkin dua tempat ini disagrees.
         */
        $tenant = $this->currentTenant();
        $blocking = $this->gate->blocking($tenant);

        if ($blocking->isNotEmpty()) {
            $this->audit->record(
                'auth.login_blocked_invoice',
                tenant: $tenant,
                subjectType: User::class,
                subjectId: $user->id,
                description: "{$user->email} ditolak login: tagihan langganan belum lunas.",
                properties: [
                    'email' => $user->email,
                    'tagihan' => $blocking->map(fn ($i) => [
                        'nomor' => $i->nomor,
                        'jumlah' => (float) $i->jumlah,
                        'deadline' => $i->deadline()->toDateString(),
                    ])->values(),
                ],
                actor: $user,
            );

            return response()->json([
                'success' => false,
                'message' => $this->gate->message($blocking),
                'code' => 'INVOICE_UNPAID',
                // `data` -- bukan spread di root -- supaya bentuknya sama
                // dengan respons `tagihan-saya` yang dipakai dashboard.
                'data' => $this->gate->payload($tenant),
            ], 402);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        /*
         * Generate jurnal piutang tunggakan, tepat di saat login.
         *
         * Kenapa di sini, bukan di frontend seperti sebelumnya:
         *
         * Generate tunggakan dulu dipicu `useOverdueGenNotification.js`
         * saat dashboard dibuka, dan hanya jalan bila HARI INI persis sama
         * dengan tanggal generate. Dua masalah muncul dari situ:
         *
         *   1. Kalau tidak ada yang membuka dashboard di tanggal itu, tagihan
         *      menunggak tidak pernah dijurnalkan sama sekali. Tidak ada error,
         *      tidak ada log. `monthly_bills` tetap `unpaid`, tapi akun Piutang
         *      Usaha `1.1.03.01` tidak pernah ditambah -- laporan under-report.
         *
         *   2. Tanggal 29/30/31 tidak pernah bisa menjadi tanggal generate
         *      karena gate-nya `===` dengan rentang 1..28.
         *
         * Login adalah titik yang jauh lebih bisa diandalkan: setiap user
         * pasti lewat di sini setiap hari kerja, dan tidak bergantung pada
         * halaman mana yang kebetulan dibuka.
         *
         * Sifatnya SINKRON dan setelah token terbit. Alasannya:
         *
         *   - Kalau generate berjalan sebelum tagihan langganan diperiksa,
         *     lokasi yang sedang ditagih akan tetap menjalankan pekerjaan
         *     akuntansi yang tidak perlu dikerjakan.
         *   - Setelah token terbit, penolakan tagihan langganan tetap bekerja
         *     dan user masih melihat popup 402 seperti biasa.
         *
         * TIDAK dijaga dengan `if ($tenant)`. `$tenant` adalah objek registry
         * `tenants`; lokasinya sendiri tetap aktif sebagai `database.default`
         * pada install satu lokasi, tempat `TenantManager::current()` bernilai
         * null karena tidak ada baris registry. Menggate di situ membuat
         * generate tidak pernah jalan justru di instalasi satu lokasi --
         * dan saat itu `TenantCache` juga akan men-scope kunci ke `pusat`.
         *
         * Gagalnya generate TIDAK membatalkan login -- lihat docblock
         * `ReceivableGenerator`.
         */
        $piutang = null;

        try {
            $piutang = $this->piutang->runIfDue();
        } catch (\Throwable $e) {
            // Sabuk pengaman: `runIfDue()` sudah menangkap Throwable miliknya
            // sendiri. Ini hanya untuk jaga-jaga bila ada kegagalan di luar
            // sana (mis. koneksi cache mati) -- login tetap harus berhasil.
            report($e);
        }

        $this->audit->record(
            'auth.login',
            tenant: $tenant,
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
                /*
                 * Slug lokasi yang sedang dilayani.
                 *
                 * Frontend menyimpannya dan mengirimkannya balik sebagai
                 * `X-Tenant-Slug` pada request berikutnya. Tanpa itu, backend
                 * tidak bisa tahu lokasi mana yang dimaksud di development
                 * lokal, tempat tidak ada subdomain per lokasi -- dan
                 * `GET /api/branding` akan selalu menjawab branding bawaan.
                 *
                 * `null` kalau tidak ada tenant aktif (login di domain pusat).
                 * Frontend membedakan `null` dari string kosong dan tidak
                 * mengirim header sama sekali pada kasus itu.
                 */
                'tenant_slug' => $tenant?->slug,

                /*
                 * Ringkasan generate piutang tunggakan yang barusan jalan
                 * di backend.
                 *
                 * Frontend tidak lagi perlu memanggil
                 * `POST /dashboard/auto-generate-overdue` untuk memastikan
                 * prosesnya terjadi -- yang tersisa hanyalah menampilkan
                 * hasilnya, dan angka di sini berasal langsung dari loop
                 * yang benar-benar membuat jurnal (bukan hasil hitung ulang
                 * dari query jurnal bertanggal hari ini, yang selalu nol
                 * saat proses diulang).
                 *
                 * `null` kalau generate tidak dijalankan: belum tanggal
                 * generate, sudah dikunci hari ini, atau prosesnya gagal.
                 * Ketiganya tidak boleh dianggap sebagai "berhasil".
                 */
                'piutang_tunggakan' => $piutang,
            ],
        ]);

    }

    /**
     * Catat percobaan login yang gagal, lalu kembalikan pesannya.
     *
     * Dipakai oleh kedua cabang kegagalan di `login()` (email tidak dikenal,
     * password salah) supaya keduanya menulis baris audit dengan bentuk yang
     * sama -- dan supaya alasannya ikut terbaca saat menelusuri log, bukan
     * hanya "percobaan gagal".
     *
     * Percobaan gagal dicatat ke database PUSAT, bukan ke database lokasi.
     * `AuditLog` mengunci koneksinya ke `central` supaya pencatatan ini tetap
     * terjadi meski `database.default` sedang menunjuk database lokasi.
     *
     * Email yang dicatat BUKAN konfirmasi bahwa email itu terdaftar di lokasi
     * mana pun -- baris ini tetap ditulis meski emailnya sama sekali tidak
     * dikenal. Yang berguna dari sini adalah IP dan user-agent-nya.
     */
    private function catatLoginGagal(Request $request, string $alasan): void
    {
        $this->audit->record(
            'auth.login_failed',
            description: 'Percobaan login lokasi gagal untuk email `'
                .$request->email.'`: '.$alasan,
            properties: ['email' => $request->email, 'alasan' => $alasan],
        );
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
