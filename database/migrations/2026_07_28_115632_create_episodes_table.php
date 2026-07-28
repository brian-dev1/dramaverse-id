Schema::create('episodes', function (Blueprint $table) {

    $table->id();

    $table->foreignId('drama_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->integer('episode_number');

    $table->string('title')->nullable();

    $table->text('description')->nullable();

    $table->string('duration')->nullable();

    /*
     |-----------------------------------------
     | Cloud Storage
     |-----------------------------------------
     */

    $table->string('cloud_provider')->nullable();

    $table->string('video_id')->nullable();

    $table->string('object_key')->nullable();

    $table->string('resolution')->nullable();

    $table->string('format')->nullable();

    $table->string('file_size')->nullable();

    /*
     |-----------------------------------------
     | Telegram
     |-----------------------------------------
     */

    $table->string('telegram_channel')->nullable();

    $table->text('telegram_caption')->nullable();

    $table->boolean('auto_publish')->default(true);

    $table->boolean('pin_message')->default(false);

    /*
     |-----------------------------------------
     | Membership
     |-----------------------------------------
     */

    $table->enum('membership', [

        'free',
        'premium',
        'vip'

    ])->default('free');

    /*
     |-----------------------------------------
     | Publish
     |-----------------------------------------
     */

    $table->enum('status',[

        'draft',
        'scheduled',
        'published'

    ])->default('draft');

    $table->timestamp('published_at')->nullable();

    $table->timestamps();

});