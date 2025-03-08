<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SchoolSeeder extends Seeder
{
    public function run()
    {
        DB::table('schools')->insert([
            [
                'name' => 'Lincoln High School',
                'address' => '123 Main St, Anytown, USA',
                'phone_number' => '555-555-5555',
                'email' => 'lincolnhigh@example.com',
            ],
            [
                'name' => 'Washington Elementary School',
                'address' => '456 Elm St, Anytown, USA',
                'phone_number' => '555-123-4567',
                'email' => 'washington elem@example.com',
            ],
            [
                'name' => 'Jefferson Middle School',
                'address' => '789 Oak St, Anytown, USA',
                'phone_number' => '555-901-2345',
                'email' => 'jeffersonmiddle@example.com',
            ],
        ]);
    }
}
