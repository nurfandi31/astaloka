<?php

namespace Database\Seeders;

use App\Models\Jabatan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $jabatanId = function (string $nama): ?int {
            return Jabatan::where('nama_jabatan', $nama)->value('id');
        };

        $users = [

            [
                'name'  => 'User Admin',
                'email' => 'admin@ASTALOKA.test',
                'role'  => 'admin',
                'jabatan_id' => $jabatanId('Direktur'),
            ],

            [
                'name'  => 'Budi Surveyor',
                'email' => 'surveyor@ASTALOKA.test',
                'role'  => 'surveyor',
                'jabatan_id' => $jabatanId('Pengawas'),
            ],

            [
                'name'  => 'Ini Teknisi',
                'email' => 'teknisi@ASTALOKA.test',
                'role'  => 'teknisi',
                'jabatan_id' => $jabatanId('Teknisi'),
            ],

            [
                'name'  => 'Andi Pelanggan',
                'email' => 'pelanggan@ASTALOKA.test',
                'role'  => 'pelanggan',
                'jabatan_id' => null,
            ],
        ];

        foreach ($users as $data) {

            User::updateOrCreate(
                ['email' => $data['email']],
                [
                    ...$data,
                    'password' => Hash::make('password')
                ]
            );
        }
    }
}
