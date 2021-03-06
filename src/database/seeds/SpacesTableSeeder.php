<?php

use Illuminate\Database\Seeder;

class SpacesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('spaces')->insert([
            'board_id'      => 1,
            'name'          => '病院',
            'position'      => 2,
            'effect_id'     => config('const.effect_change_status'),
            'effect_num'    => config('const.piece_status_health')
        ]);
    }
}
