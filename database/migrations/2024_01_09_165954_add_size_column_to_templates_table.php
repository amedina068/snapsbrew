<?php

use App\Models\Template;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSizeColumnToTemplatesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->string('size')->after('layers')->nullable()->index();
        });

        Template::query()->cursor()->each(function ($template) {
            $size = $template->width.'x'.$template->height;

            $sizeValue = match ($size) {
                '1080x1920' => 'instagram_story',
                '1080x1350' => 'instagram_post',
                '1200x670' => 'twitter_card',
                '1200x1200' => 'facebook_card',
                default => 'custom',
            };

            $template->update(['size' => $sizeValue]);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('templates', function (Blueprint $table) {
            //
        });
    }
}
