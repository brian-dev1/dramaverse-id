Schema::create('dramas', function (Blueprint $table) {

    $table->id();

    $table->string('title');

    $table->string('original_title')->nullable();

    $table->string('slug')->unique();

    $table->string('poster')->nullable();

    $table->text('synopsis')->nullable();

    $table->string('country')->nullable();

    $table->year('release_year')->nullable();

    $table->string('status')->default('ongoing');

    $table->integer('total_episode')->default(0);

    $table->string('duration')->nullable();

    $table->string('quality')->nullable();

    $table->string('membership')->default('free');

    $table->boolean('published')->default(false);

    $table->timestamps();

});