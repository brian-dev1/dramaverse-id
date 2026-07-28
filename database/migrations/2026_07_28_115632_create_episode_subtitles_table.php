Schema::create('episode_subtitles', function (Blueprint $table) {

    $table->id();

    $table->foreignId('episode_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->string('language');

    $table->string('format');

    $table->string('path');

    $table->timestamps();

});