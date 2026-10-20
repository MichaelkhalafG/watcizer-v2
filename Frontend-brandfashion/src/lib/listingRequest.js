// The listing request. BOTH storefronts read core's v2 listing, `/api/v2/{storefront}/products`, through
// src/lib/v2Listing.js — Brand Fashion since 2026-10-17 (V2-READ-HALF, Michael's decision: both storefronts
// move to v2, Brand Fashion pilots) and Watchizer since 2026-10-22, when the pilot had carried real orders.
//
// The three names below keep every import site unchanged (useListing, serverCatalog, the listing pages): the
// request string is still the query key, and the payload is normalised to the shape those consumers read.
// This file is a TWIN again as of the second move — it was split only for as long as the two shops read
// different APIs.
export { v2ListingRequest as listingRequest, v2ListingQueryFn as listingQueryFn, listingNeedsTables } from './v2Listing'
