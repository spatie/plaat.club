<?php

namespace App\Actions\Discogs;

use App\Actions\Collections\StoreReleaseAction;
use App\Models\Release;
use App\Support\Discogs;
use App\Support\DiscogsRecordMapper;

class ImportReleaseInfoAction
{
    public function __construct(
        protected Discogs $discogs,
        protected DiscogsRecordMapper $mapper,
        protected StoreReleaseAction $storeRelease,
    ) {}

    public function execute(int $releaseId): Release
    {
        $release = $this->discogs->get("releases/{$releaseId}");
        $info = $this->mapper->releaseInfo($release, $this->discogs->artistBios($release['artists'] ?? []));
        $info['discogsTextLinks'] = $this->discogs->textLinks($info);

        return $this->storeRelease->execute($releaseId, $info, replace: true);
    }
}
