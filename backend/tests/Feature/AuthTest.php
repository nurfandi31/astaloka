<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $role = 'admin'): User
    {
        return User::create([
            'name'     => 'Test User',
            'email'    => 'test@pdam.test',
            'password' => Hash::make('password'),
            'role'     => $role,
        ]);
    }

    #[Test]
    public function user_dapat_login(): void
    {
        $this->createUser();

        $response = $this->postJson('/api/login', [
            'email'    => 'test@pdam.test',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'success',
                     'data' => ['user', 'token'],
                 ]);
    }

    #[Test]
    public function login_gagal_dengan_kredensial_salah(): void
    {
        $this->createUser();

        $response = $this->postJson('/api/login', [
            'email'    => 'test@pdam.test',
            'password' => 'salah123',
        ]);

        $response->assertStatus(422);
    }

    /*
     * Tiga test di bawah menguji satu keputusan yang sama: kegagalan login
     * ditunjuk ke KOLOMNYA, bukan digabung semua menjadi satu popup
     * "email atau password salah".
     *
     * Yang ditekankan di sini adalah field yang dipakai sebagai kunci `errors`.
     * Frontend (`composables/useLoginError.js`) membaca kunci itu untuk
     * menempelkan pesan ke input yang tepat, jadi pesan yang salah field bukan
     * hanya kosmetik: yang tampil adalah pesan lama di bawah kolom yang
     * sebenarnya tidak bermasalah, dan kolom yang bermasalah tidak diberi
     * tanda sama sekali.
     */
    #[Test]
    public function password_salah_dilaporkan_di_kolom_password(): void
    {
        $this->createUser();

        $res = $this->postJson('/api/login', [
            'email'    => 'test@pdam.test',
            'password' => 'salah123',
        ])->assertUnprocessable();

        $this->assertSame(['Kata sandi salah.'], $res->json('errors.password'));
        $this->assertArrayNotHasKey('email', $res->json('errors'));
    }

    #[Test]
    public function email_tidak_terdaftar_dilaporkan_di_kolom_email(): void
    {
        $this->createUser();

        $res = $this->postJson('/api/login', [
            'email'    => 'entah@pdam.test',
            'password' => 'password',
        ])->assertUnprocessable();

        $this->assertSame(
            ['Email tidak terdaftar di lokasi ini.'],
            $res->json('errors.email'),
        );
        $this->assertArrayNotHasKey('password', $res->json('errors'));
    }

    /**
     * Kedua-duanya salah -> hanya yang paling atas yang ditunjuk.
     *
     * Frontend menempelkan pesan di bawah kolomnya, jadi dua pesan sekaligus
     * hanya menambah tinggi form tanpa menambah informasi: begitu emailnya
     * dikoreksi, jawabannya sudah pasti ada di kolom password.
     */
    #[Test]
    public function email_dan_password_salah_hanya_menunjuk_email(): void
    {
        $this->createUser();

        $res = $this->postJson('/api/login', [
            'email'    => 'entah@pdam.test',
            'password' => 'salah123',
        ])->assertUnprocessable();

        $this->assertSame(
            ['Email tidak terdaftar di lokasi ini.'],
            $res->json('errors.email'),
        );
        $this->assertArrayNotHasKey('password', $res->json('errors'));
    }

    #[Test]
    public function user_dapat_logout(): void
    {
        $user  = $this->createUser();
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                         ->postJson('/api/logout');

        $response->assertStatus(200)
                 ->assertJson(['success' => true]);
    }

    #[Test]
    public function akses_endpoint_tanpa_token_ditolak(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertStatus(401);
    }
}
