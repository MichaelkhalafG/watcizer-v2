// The words search engines and share sheets read for the two campaign pages (the design pass, 2026-11-05).
// NOT a twin: each shop says this in its own name. The pages themselves (app/(main)/hot-deals, /flash-sale) are twins.
//
// The pages are PERMANENT: they stay up, and indexable, between campaigns — only their products change. So the
// words describe the page, never one campaign: no discount, no date and no product is promised here.
export const CAMPAIGN_META = {
  hot_deals: {
    path: '/hot-deals',
    en: {
      title: 'Hot Deals: limited-time prices on original watches | Watchizer',
      description:
        'The Hot Deals page of Watchizer. While a deal is running, its watches are listed here at their deal prices with the time left; between deals the page says so. Original watches, delivered across Egypt.',
    },
    ar: {
      title: 'عروض ساخنة: أسعار لفترة محدودة على ساعات أصلية | Watchizer',
      description:
        'صفحة العروض الساخنة في واتشايزر. أثناء العرض تظهر ساعاته هنا بأسعار العرض مع الوقت المتبقي، وبين العروض توضّح الصفحة ذلك. ساعات أصلية مع توصيل لكل محافظات مصر.',
    },
  },
  flash_sale: {
    path: '/flash-sale',
    en: {
      title: 'Flash Sale: short sales on original watches | Watchizer',
      description:
        'The Flash Sale page of Watchizer. A flash sale is a short sale on a chosen set of watches: while one is running they are listed here with the countdown to its end. Original watches, delivered across Egypt.',
    },
    ar: {
      title: 'تخفيضات خاطفة: تخفيضات قصيرة على ساعات أصلية | Watchizer',
      description:
        'صفحة التخفيضات الخاطفة في واتشايزر: تخفيض قصير على ساعات مختارة، تظهر هنا مع العدّاد حتى نهايته. ساعات أصلية مع توصيل لكل محافظات مصر.',
    },
  },
}
