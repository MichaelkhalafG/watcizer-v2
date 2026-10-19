// Brand Fashion's listing request — DIVERGED from Frontend-next on 2026-10-17 (V2-READ-HALF, Michael's
// decision: both storefronts move to v2, Brand Fashion pilots). This storefront reads core's v2 listing,
// `/api/v2/brandfashion/products`, through src/lib/v2Listing.js; Watchizer still reads compat
// `catalog/listing`. The two names below keep every import site unchanged (useListing, serverCatalog,
// the listing pages): the request string is still the query key, and the payload is normalised to the
// shape those consumers read. Re-twin this file (TWINS.txt) when Watchizer's listing follows.
export { v2ListingRequest as listingRequest, v2ListingQueryFn as listingQueryFn, listingNeedsTables } from './v2Listing'
