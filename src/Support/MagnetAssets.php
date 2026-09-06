<?php

namespace Goldnead\LeadMagnets\Support;

use Statamic\Assets\AssetContainer as StatamicContainer;
use Statamic\Contracts\Assets\AssetContainer as ContainerContract;
use Statamic\Facades\AssetContainer;

/**
 * The addon's own asset container: where a file uploaded in the Control Panel
 * lands, and the one question that matters about it — can the web reach it.
 *
 * A resource behind a double opt-in is not a public file. Putting it into a
 * Statamic container is only safe if the container's disk has no way out
 * except the signed download route, so that property is asserted here rather
 * than assumed, and the resource form shows it when it does not hold.
 */
class MagnetAssets
{
    public function containerHandle(): string
    {
        return (string) config('lead-magnets.assets.container', 'lead_magnets');
    }

    public function diskHandle(): string
    {
        return (string) config('lead-magnets.assets.disk', 'lead-magnets');
    }

    public function container(): ?ContainerContract
    {
        return AssetContainer::findByHandle($this->containerHandle());
    }

    /**
     * Create the container when it is not there yet, and hand it back.
     *
     * Called from the two Control Panel actions that render the file picker,
     * because that is the only moment the container is needed and the only
     * moment somebody with `manage lead magnets` is on the other end. There is
     * no install step to forget and nothing for the host application to add to
     * its own config — an addon that only worked after a command nobody ran
     * would show an empty picker and no reason for it.
     *
     * Idempotent: the lookup is a Stache read, and every call after the first
     * returns without writing.
     */
    public function ensureContainer(): ContainerContract
    {
        if (($existing = $this->container()) !== null) {
            return $existing;
        }

        $container = AssetContainer::make($this->containerHandle());
        $container->title(__('lead-magnets::resources.container_title'));

        // `disk()` is on the class, not on the contract the facade promises.
        if ($container instanceof StatamicContainer) {
            $container->disk($this->diskHandle());
        }

        $container->save();

        return $container;
    }

    /**
     * Whether the container's disk can be reached from the web.
     *
     * Three ways it can be, and Statamic's own `AssetContainer::private()`
     * knows only the first:
     *
     * 1. The disk has a `url`. Every asset then has a public address and
     *    Statamic hands it out — that is what `private()` reads.
     * 2. The disk's visibility is `public`. Laravel's `/storage` route for a
     *    served disk asks for a signature only while the visibility is
     *    private (`Illuminate\Filesystem\ServeFile`), so a public one is
     *    fetched by anyone who guesses the name.
     * 3. The disk is local and rooted inside the document root. Then no PHP is
     *    involved at all: the web server hands the file over before Laravel
     *    sees the request, `url` or not.
     *
     * Statamic's own default asset disk, `assets`, is all three at once — it
     * is `public/assets` with a URL, which is exactly right for the images on
     * a page and exactly wrong for a resource behind a double opt-in. That is
     * the trap in "just add an asset fieldtype": the fieldtype's container
     * config defaults to the site's one existing container, which on a
     * standard install is that one.
     */
    public function diskIsPublic(): bool
    {
        $config = (array) config('filesystems.disks.'.$this->diskHandle());

        if (($config['url'] ?? null) !== null || ($config['visibility'] ?? null) === 'public') {
            return true;
        }

        if (($config['driver'] ?? null) !== 'local') {
            return false;
        }

        $root = realpath((string) ($config['root'] ?? '')) ?: (string) ($config['root'] ?? '');
        $public = realpath(public_path()) ?: public_path();

        return $root !== '' && str_starts_with($root.DIRECTORY_SEPARATOR, rtrim($public, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }
}
