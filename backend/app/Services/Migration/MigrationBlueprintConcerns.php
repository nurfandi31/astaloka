<?php

namespace App\Services\Migration;

/**
 * Perilaku bersama untuk semua blueprint migrasi.
 *
 * Dipisah dari interface karena interface tidak boleh punya isi, dan
 * {@see MigrationBlueprint::tanpaCadangan()} adalah satu-satunya method
 * di kontrak itu yang butuh implementasi. Menaruhnya di trait membuat
 * lima blueprint memakai satu implementasi yang sama -- bukan lima
 * salinan yang bisa berbeda satu karakter di antara mereka.
 */
trait MigrationBlueprintConcerns
{
    /**
     * Buang key internal, sisakan yang memang kolom database.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function tanpaCadangan(array $data): array
    {
        return array_filter(
            $data,
            static fn ($kunci) => ! str_starts_with((string) $kunci, MigrationBlueprint::KUNCI_CADANGAN),
            ARRAY_FILTER_USE_KEY
        );
    }
}
