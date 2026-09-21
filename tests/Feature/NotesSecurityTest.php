<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

/** Real framework tests. Use an isolated in-memory SQLite DB, never a configured live database. */
class NotesSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'notes_service.username' => 'local-service',
            'notes_service.password' => 'local-password',
        ]);
        DB::purge('sqlite');
        Schema::create('notes', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->string('note_id');
            $table->text('title')->default(''); $table->text('text')->default('');
            $table->unsignedBigInteger('last_modified');
            foreach (['protected','auto_wipe','deleted','pinned','favorite'] as $flag) $table->boolean($flag)->default(false);
            $table->string('checksum_hmac')->nullable(); $table->string('folder_id')->nullable();
            $table->string('folder')->nullable(); $table->timestamps();
        });
        Schema::create('folders', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->string('folder_id');
            $table->string('name'); $table->unsignedBigInteger('last_modified');
            $table->boolean('deleted')->default(false); $table->timestamps();
            $table->unique(['user_id','folder_id']);
        });
        (require database_path('migrations/2026_09_21_220000_create_notes_upload_receipts.php'))->up();
    }

    private function service(): static
    {
        return $this->withHeaders(['Authorization' => 'Basic '.base64_encode('local-service:local-password')]);
    }

    public function test_routes_require_service_authentication(): void
    {
        foreach (['find','download','upload','sync-plan'] as $action) {
            $this->postJson('/api/v1/notecontroller/'.$action, ['user_id'=>1])->assertStatus(401);
        }
        $this->withHeaders(['Authorization'=>'Basic '.base64_encode('wrong:wrong')])
            ->postJson('/api/v1/notecontroller/download', ['user_id'=>1])->assertStatus(401);
        $this->service()->postJson('/api/v1/notecontroller/download', ['user_id'=>'1'])
            ->assertExactJson(['notes'=>[], 'folders'=>[]]);
    }

    public function test_receipts_isolate_users_and_preserve_both_response_contracts(): void
    {
        $url='/api/v1/notecontroller/upload';
        $payload=['user_id'=>1,'notes'=>[['id'=>'n','text'=>'ciphertext','last_modified'=>1]]];
        $this->service()->withHeaders(['Idempotency-Key'=>'same']);
        $this->postJson($url,$payload)->assertExactJson(['ok'=>true]);
        $this->postJson($url,$payload)->assertExactJson(['ok'=>true]);
        $this->assertSame(1, DB::table('notes')->count());
        $this->postJson($url,array_replace($payload,['user_id'=>2]))->assertExactJson(['ok'=>true]);
        $this->assertSame(2, DB::table('notes')->count());
        $changed=$payload; $changed['notes'][0]['text']='different';
        $this->postJson($url,$changed)->assertStatus(409);
        $this->withHeaders(['Idempotency-Key'=>'ack']);
        $payload['require_note_ack']=true;
        $this->postJson($url,$payload)->assertExactJson(['ok'=>true,'note_ack_v1'=>true]);
        $this->postJson($url,$payload)->assertExactJson(['ok'=>true,'note_ack_v1'=>true]);
    }

    public function test_folder_id_collision_cannot_expose_another_accounts_folder(): void
    {
        DB::table('folders')->insert([
            ['user_id'=>2,'folder_id'=>'same','name'=>'PRIVATE OTHER ACCOUNT','last_modified'=>1],
            ['user_id'=>1,'folder_id'=>'same','name'=>'My folder','last_modified'=>1],
        ]);
        DB::table('notes')->insert(['user_id'=>1,'note_id'=>'n','folder_id'=>'same','last_modified'=>1]);
        $found = $this->service()->postJson('/api/v1/notecontroller/find',['user_id'=>1,'id'=>'n'])
            ->assertJsonPath('folder','My folder');
        $this->assertArrayNotHasKey('folder_entity', $found->json());
        $this->postJson('/api/v1/notecontroller/download',['user_id'=>1])
            ->assertJsonPath('notes.0.folder','My folder');
    }
}
