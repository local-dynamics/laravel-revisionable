<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRevisionsTable extends Migration
{
    public function up()
    {
        Schema::create('revisions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('revisionable_type');
            $table->unsignedBigInteger('revisionable_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('key');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->char('process', 8)->nullable();
            $table->char('revision', 8)->nullable();
            $table->timestamp('created_at');

            $table->index([
                'revisionable_id',
                'revisionable_type',
            ]);
        });
    }

    public function down()
    {
        Schema::dropIfExists('revisions');
    }
}
