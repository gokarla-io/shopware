# Catalog sync for independent brands

Configure the Karla shop slug, credentials and Product Sync toggle for each
Shopware sales channel. The existing salesChannelMapping takes precedence over
the channel's shop slug, consistent with order routing.

When mappings or channel-specific shop/product-sync settings exist, product
writes and queued full syncs query each enabled channel's product visibility.
Variants use Shopware's inherited visibility. Each request uses that channel's
shop and credentials. Product deletion is sent to enabled channels, since the
removed entity no longer carries its visibility assignments.

A global full-sync job fans out into channel jobs. Channel jobs retain their
channel across subsequent batches and write completion/failure status in that
channel. Disabled channels stop processing queued batches. A channel's full-sync
cooldown is independent of global and other-channel syncs.

Installations without any channel settings or mapping keep legacy catalog sync.
Before rollout, use a brand-exclusive product and a shared product to verify the
resulting catalogs, then test an update and deletion. Changing channel mappings
or visibility does not purge products already synced into the old Karla catalog;
review and clean those entries before switching an existing catalog's ownership.
