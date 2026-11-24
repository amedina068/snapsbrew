<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTemplateTemplateCollectionTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('template_template_collection', function (Blueprint $table) {
            $table->foreignId('template_id');
            $table->foreignId('template_collection_id');
            $table->timestamps();
            $table->index(['template_id', 'template_collection_id'], 'temp_temp_coll_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('template_template_collection');
    }
}
