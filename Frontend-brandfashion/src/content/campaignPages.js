// The words search engines and share sheets read for the two campaign pages (the design pass, 2026-11-05).
// NOT a twin: each shop says this in its own name. The pages themselves (app/(main)/hot-deals, /flash-sale) are twins.
//
// The pages are PERMANENT: they stay up, and indexable, between campaigns — only their products change. So the
// words describe the page, never one campaign: no discount, no date and no product is promised here.
export const CAMPAIGN_META = {
  hot_deals: {
    path: '/hot-deals',
    en: {
      title: 'Hot Deals: limited-time prices on watches, bags and accessories | Brand Fashion',
      description:
        'The Hot Deals page of Brand Fashion. While a deal is running, its products are listed here at their deal prices with the time left; between deals the page says so. Delivered across Egypt.',
    },
    ar: {
      title: 'عروض ساخنة: أسعار لفترة محدودة على الساعات والشنط والإكسسوارات | براند فاشون',
      description:
        'صفحة العروض الساخنة في براند فاشون. أثناء العرض تظهر منتجاته هنا بأسعار العرض مع الوقت المتبقي، وبين العروض توضّح الصفحة ذلك. توصيل لكل محافظات مصر.',
    },
  },
  flash_sale: {
    path: '/flash-sale',
    en: {
      title: 'Flash Sale: short sales on watches, bags and accessories | Brand Fashion',
      description:
        'The Flash Sale page of Brand Fashion. A flash sale is a short sale on a chosen set of products: while one is running they are listed here with the countdown to its end. Delivered across Egypt.',
    },
    ar: {
      title: 'تخفيضات خاطفة: تخفيضات قصيرة على الساعات والشنط والإكسسوارات | براند فاشون',
      description:
        'صفحة التخفيضات الخاطفة في براند فاشون: تخفيض قصير على منتجات مختارة، تظهر هنا مع العدّاد حتى نهايته. توصيل لكل محافظات مصر.',
    },
  },
}
