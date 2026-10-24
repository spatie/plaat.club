<?php

use App\Bots\CollectionSearchBot;
use Laravel\Converse\Facades\Converse;

Converse::bot('collection', CollectionSearchBot::class)->connection('web');
