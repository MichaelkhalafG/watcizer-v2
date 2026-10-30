# Dashboard filters and search, everywhere — record (2026-10-30)

> **Corrected 2026-10-30, after the final gate:** the quick searches on Categories, Lists and Articles read
> `name.ar` / `name.en` (`title.*`) one by one, which `ProductNameSeamTest` forbids on a screen; they now pass the
> pair (`...Object.values(node.name)`), the same search. And two RTL guard failures from items 2–3 were fixed the
> same evening: a hand-rolled table in the bulk-price dialog (now `components/ui/table`), a `dir` on a block in the
> activity screen (now `<Num>`).

Michael's item 4. Measured first: two kinds of list screen exist.

- **Server-paged lists** (`App\Support\Table\TableQuery` + `DataTable`): search, filters, sort, page size and page live
  in the URL (`?q=`, `filters[…]`, `sort`, `direction`, `per_page`, `page`) — they survive a refresh and can be sent —
  and the table has a "Clear the filters" button and a CSV of the current view. Consistent by construction.
- **Whole-list screens** (the server sends every row: tens, not thousands): until now no search at all on most.
  They now share one component, `components/table/QuickSearch.tsx`: a search box filtering in the browser, the term
  in the URL (`?q=`), a "n of N" count and the same "Clear the filters" button, with Arabic letter variants folded
  (أ/إ/آ → ا, ة → ه, ى → ي, short vowels dropped) so «اوميجا» finds «أوميغا»-style spellings that differ only there.

| Page | Search — before | Search — after | Filters — before → after |
|---|---|---|---|
| Products | name AR/EN, internal code, SKU (server) | unchanged | family, brand, active, stock, visible, featured, storefront scope, category branch, flags → **+ grade, + price range (charged price)** (item 2) |
| Orders | order number (prefix), phone (guest, address, account) | **+ customer name (guest or account), + e-mail (guest or account)** | storefront, status, provider, method, date range — unchanged |
| Customers | phone, e-mail, name | unchanged | kind, has orders, storefront — unchanged |
| Promotions | rule name | **+ coupon code** | active, storefront — unchanged |
| Activity log | record name / user (prefix), record number | **+ product SKU (any part), internal code, product title AR/EN** (item 3) | user, action, record type, storefront, date range → **+ kind (price / stock / bulk / sign-in)** |
| Stock (inventory) | product name / code | unchanged | view, bucket, family, brand, category, storefront; ledger: reason, bucket, product, storefront, reference, dates — unchanged |
| Placement | product name / code | unchanged | visible, featured, category, flag — unchanged |
| Storefronts | code, name, domain | unchanged | active — unchanged |
| Banners | none | none — a banner has no text to search (an image, a link, dates) | active, state, device — unchanged |
| Brands and the other lists (lookups) | none | **name AR/EN (quick search)** | — |
| Categories (tree) | none | **name AR/EN or link; a match shows with its ancestors (quick search)** | fold / unfold all — unchanged |
| Shipping prices | none | **governorate AR/EN (quick search)** | — |
| Units | none | **code or name (quick search)** | — |
| Articles (blogs) | none | **title AR/EN or link (quick search)** | **+ status (published / draft), + storefront** (kept in the URL) |
| Users | name / e-mail (existing) | unchanged | existing |
| Home rails | — | not added: an ORDERING screen of 30 rails, read top to bottom | — |
| Payments, re-engagement, profile, storefront settings | — | not lists (settings forms) | — |
| Reviews | — | **no reviews screen exists in the dashboard** (product ratings have no list screen today) | — |

Tests: `tests/Feature/Manage/ListSearchTest.php` (orders by name, e-mail part, number, phone; a promotion by its
code); `BulkPriceTest` covers the products grade and price-range filters; `ActivityFindTest` the log's search and
kinds; `TranslationCoverageTest` green (the folding table is marked as data). Browser on the scratch copy: brands
`?q=rol` → 1 of 80 (Rolex); categories `?q=gmt` → «جي إم تي» shown under «ساعات», 1 of 45.

Recommendation (not done): a reviews screen, if product ratings should be moderated from the dashboard.
