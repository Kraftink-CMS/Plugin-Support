<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createBaseTables();
        $this->createRelatedTables();
    }

    public function down(): void
    {
        foreach (
            [
                'support__message_revisions',
                'support__files',
                'support__drafts',
                'support__templates',
                'support__category_roles',
            ] as $table
        ) {
            Schema::dropIfExists($table);
        }
        Schema::dropIfExists('support__ticket_events');
        Schema::dropIfExists('support__field_values');
        Schema::dropIfExists('support__fields');
        Schema::dropIfExists('support__messages');
        Schema::dropIfExists('support__tickets');
        Schema::dropIfExists('support__categories');
    }

    private function createBaseTables(): void
    {
        Schema::create('support__categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('locale', 12);
            $table->string('icon', 60)->default('life-preserver');
            $table->text('introduction')->nullable();
            $table->boolean('structured_form_only')->default(false);
            $table->string('access', 16)->default('members');
            $table->string('permission', 100)->nullable();
            $table->unsignedInteger('first_response_minutes')->nullable();
            $table->unsignedInteger('resolution_minutes')->nullable();
            $table->unsignedInteger('auto_close_hours')->nullable();
            $table->boolean('auto_close_unanswered')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('position')->default(0);
        });
        Schema::create('support__tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('support__categories')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 180);
            $table->string('status', 16)->default('open');
            $table->string('priority', 16)->default('normal');
            $table->timestamp('first_staff_reply_at')->nullable();
            $table->timestamp('last_staff_reply_at')->nullable();
            $table->timestamp('first_response_due_at')->nullable()->index();
            $table->timestamp('resolution_due_at')->nullable()->index();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
            $table->index(['deleted_at', 'id'], 'support_tickets_trash_index');
            $table->index(['status', 'priority', 'updated_at']);
            $table->index(['assigned_to', 'status', 'updated_at']);
        });
        Schema::create('support__messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support__tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->boolean('internal')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();
            $table->index(['ticket_id', 'id']);
            $table->index(['deleted_at', 'id'], 'support_messages_trash_index');
        });
        Schema::create('support__fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('support__categories')->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('label', 120);
            $table->string('help_text', 500)->nullable();
            $table->string('type', 16);
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('staff_only')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->unique(['category_id', 'key']);
        });
        Schema::create('support__field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support__tickets')->cascadeOnDelete();
            $table->foreignId('field_id')->nullable()->constrained('support__fields')->nullOnDelete();
            $table->string('field_label', 120);
            $table->boolean('staff_only')->default(false);
            $table->text('value')->nullable();
            $table->unique(['ticket_id', 'field_id']);
        });
        Schema::create('support__ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support__tickets')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->json('data')->nullable();
            $table->timestamp('created_at');
            $table->index(['ticket_id', 'id']);
        });
    }

    private function createRelatedTables(): void
    {
        Schema::create('support__message_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('support__messages')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->boolean('anonymized')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['message_id', 'id']);
        });
        Schema::create('support__category_roles', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained('support__categories')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->primary(['category_id', 'role_id']);
        });
        Schema::create('support__templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('support__categories')->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('locale', 12);
            $table->string('kind', 16)->default('reply');
            $table->text('body');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['locale', 'kind', 'enabled', 'position']);
        });
        Schema::create('support__drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('context', 80);
            $table->longText('payload');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['user_id', 'context']);
        });
        Schema::create('support__files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->nullable()->constrained('support__messages')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('context', 80);
            $table->boolean('inline')->default(false);
            $table->string('path')->unique();
            $table->string('name', 180);
            $table->string('mime', 80);
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('created_at');
            $table->index(['user_id', 'context']);
        });
    }
};
