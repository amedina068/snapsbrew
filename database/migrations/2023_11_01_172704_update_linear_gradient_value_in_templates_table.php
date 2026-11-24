<?php

use App\Models\Template;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateLinearGradientValueInTemplatesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Template::query()->cursor()->each(function ($template) {
            $layers = collect(json_decode($template->layers, true))->map(function ($layer) {
                $properties = collect($layer['properties'])->map(function ($property) {
                    if ($property['name'] == 'backgroundGradientColor') {
                        $property['value'] = [
                            'steps' => [
                                [
                                    'color' => $property['value']['from'],
                                    'percentage' => null,
                                ],
                                [
                                    'color' => $property['value']['to'],
                                    'percentage' => null,
                                ],
                            ],
                            'degrees' => $property['value']['degrees'],
                        ];
                        unset($property['value']['from']);
                        unset($property['value']['to']);
                    }

                    return $property;
                });
                $layer['properties'] = $properties;

                return $layer;
            });

            $template->update([
                'layers' => json_encode($layers),
            ]);
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
