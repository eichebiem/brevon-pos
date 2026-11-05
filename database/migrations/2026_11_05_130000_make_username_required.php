<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every account signs in with a username (D-099). Accounts created before
 * this change without one get a username from their email address
 * (e.g. maria.santos@example.com → maria.santos), made unique with a number.
 */
return new class extends Migration
{
    public function up(): void
    {
        $taken = DB::table('users')->whereNotNull('username')->pluck('username')->map(fn ($u) => (string) $u)->flip()->all();

        DB::table('users')->whereNull('username')->orderBy('id')->get(['id', 'email'])->each(function (object $user) use (&$taken) {
            $base = Str::of(Str::before((string) $user->email, '@'))->lower()->ascii()
                ->replaceMatches('/[^a-z0-9._-]/', '')->ltrim('._-')->limit(70, '')->toString();
            $base = strlen($base) >= 3 ? $base : 'user'.$user->id;
            $candidate = $base;

            for ($n = 2; isset($taken[$candidate]); $n++) {
                $candidate = $base.$n;
            }

            $taken[$candidate] = true;
            DB::table('users')->where('id', $user->id)->update(['username' => $candidate]);
        });

        DB::statement('ALTER TABLE users MODIFY username VARCHAR(80) NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users MODIFY username VARCHAR(80) NULL');
    }
};
