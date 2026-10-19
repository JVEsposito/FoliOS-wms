<?php

namespace Tests\Feature\Finanzas;

use Pdo\Mysql;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConexionFinanzasTest extends TestCase
{
    public function test_db_url_se_hereda_y_el_usuario_de_lectura_tiene_precedencia(): void
    {
        // Proceso separado: el repositorio inmutable de env no reutiliza el entorno de la suite.
        $proceso = new Process([PHP_BINARY, '-r', <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            echo json_encode($app['config']->get('database.connections'));
            PHP,
        ], base_path(), [
            'DB_URL' => 'mysql://editor:clave%40url@db.invalid:3340/operacion_url',
            'MYSQL_ATTR_SSL_CA' => '/certificado/prueba.pem',
            'FINANZAS_LECTURA_DB_USERNAME' => 'solo_lectura',
            'FINANZAS_LECTURA_DB_PASSWORD' => 'clave_lectura',
            'FINANZAS_DB_URL' => false, 'FINANZAS_DB_HOST' => false,
            'FINANZAS_DB_PORT' => false, 'FINANZAS_DB_DATABASE' => false,
            'FINANZAS_DB_USERNAME' => false, 'FINANZAS_DB_PASSWORD' => false,
        ]);
        $proceso->mustRun();
        $conexiones = json_decode($proceso->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $lectura = $conexiones['operacion_lectura'];
        $this->assertSame('db.invalid', $lectura['host']);
        $this->assertSame('operacion_url', $lectura['database']);
        $this->assertSame('solo_lectura', $lectura['username']);
        $this->assertSame('clave_lectura', $lectura['password']);
        $this->assertArrayNotHasKey('url', $lectura);
        $this->assertSame('/certificado/prueba.pem', $lectura['options'][Mysql::ATTR_SSL_CA]);
        $this->assertSame('SET SESSION TRANSACTION READ ONLY', $lectura['options'][Mysql::ATTR_INIT_COMMAND]);
        $this->assertSame('operacion_url_finanzas', $conexiones['finanzas']['database']);
        $this->assertSame('db.invalid', $conexiones['finanzas']['host']);
        $this->assertSame('editor', $conexiones['finanzas']['username']);
        $this->assertSame('clave@url', $conexiones['finanzas']['password']);
    }
}
