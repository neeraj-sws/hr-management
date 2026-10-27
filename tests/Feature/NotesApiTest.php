<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\EmployeeNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Notes in the mobile and desktop apps: own notes add / edit / delete; shared notes read only. */
class NotesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_manages_own_notes_and_reads_shared_ones(): void
    {
        $this->seed();
        $bossUser = User::factory()->create();
        $bossUser->assignRole('Manager');
        $boss = AttendanceEmployee::create(['employee_code' => 'NB-1', 'name' => 'Boss Person', 'is_active' => true, 'user_id' => $bossUser->id, 'joined_at' => '2026-01-01']);
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $me = AttendanceEmployee::create(['employee_code' => 'NE-1', 'name' => 'Riya Sharma', 'is_active' => true, 'user_id' => $user->id, 'manager_id' => $boss->id, 'joined_at' => '2026-01-01']);
        $fromBoss = EmployeeNote::create(['author_id' => $bossUser->id, 'employee_id' => $me->id, 'visibility' => 'manager', 'title' => 'Feedback', 'body' => 'Good work this week']);
        $bossPrivate = EmployeeNote::create(['author_id' => $bossUser->id, 'visibility' => 'personal', 'title' => 'Boss only', 'body' => 'Private']);

        Sanctum::actingAs($user);
        $id = $this->postJson('/api/notes', ['title' => 'Call client', 'body' => 'Ask about the invoice'])->assertCreated()->assertJsonPath('data.shared', 'Private')->json('data.id');
        $this->postJson('/api/notes', ['title' => ''])->assertStatus(422);

        $list = collect($this->getJson('/api/notes')->assertOk()->json('data'));
        $this->assertSame(['Call client', 'Feedback'], $list->pluck('title')->sort()->values()->all(), "Own + manager's note, never the boss's private note");
        $this->assertTrue($list->firstWhere('title', 'Call client')['can_edit']);
        $this->assertFalse($list->firstWhere('title', 'Feedback')['can_edit']);
        $this->assertSame('From '.$bossUser->name, $list->firstWhere('title', 'Feedback')['shared']);

        $this->patchJson("/api/notes/{$id}", ['title' => 'Call client today', 'body' => 'Invoice 42'])->assertOk()->assertJsonPath('data.title', 'Call client today');
        $this->patchJson("/api/notes/{$fromBoss->id}", ['title' => 'x', 'body' => 'y'])->assertForbidden();
        $this->deleteJson("/api/notes/{$fromBoss->id}")->assertForbidden();
        $this->deleteJson("/api/notes/{$bossPrivate->id}")->assertForbidden();
        $this->deleteJson("/api/notes/{$id}")->assertOk();
        $this->assertDatabaseMissing('employee_notes', ['id' => $id]);
        $this->assertDatabaseHas('employee_notes', ['id' => $fromBoss->id]);
    }
}
