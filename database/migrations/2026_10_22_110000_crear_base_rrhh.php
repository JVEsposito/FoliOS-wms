<?php

use App\Rrhh\EsquemaRrhh;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(EsquemaRrhh::class)->asegurarBase();
    }

    public function down(): void {}
};
