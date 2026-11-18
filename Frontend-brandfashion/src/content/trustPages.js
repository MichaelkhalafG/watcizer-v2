// The four trust pages — /about-us, /contact-us, /privacy-policy, /terms-and-conditions — for BRAND FASHION.
//
// Round 5, 2026-10-18 (Michael's answers; the record is core/docs/deploy/RUN_20261018.md):
//  • About and Privacy are DRAFTS for Michael's review — Watchizer's approved text of 2026-10-01 rewritten
//    with Brand Fashion's facts. Only what is sourced is said: the client's confirmed contact details (C-4),
//    shipping identical to Watchizer (C-2), cash on delivery as the one payment storefront 2 offers today,
//    Meta as the only tracker (D8). No opening hours (not confirmed for Brand Fashion), no card payment.
//  • Contact: the client's details (C-4). There is no Facebook (Michael); Instagram is brand_fashion_eg.
//  • Terms: Watchizer's approved terms AS THEY STAND (Michael: the returns policy is Watchizer's), with the
//    shop's name — and the Payment section limited to cash on delivery, the only method storefront 2 has.
// The customer-service number is Brand Fashion's (D22: the developer's number stays in the footer credit only).
// Inline text supports **bold**, *italic* and [label](href), rendered as React elements by TrustPage, never as
// HTML. Every href here is one of the constants below.

// The date shown as "Last updated" on the privacy policy and the terms. Set it to the day the
// pages are first published (deploy day), and again whenever their text changes.
export const TRUST_UPDATED = '2026-11-18'

// the number and the accounts are typed once, in ./contact.js (2026-11-14: 01014179820 replaced the old number)
import { PHONE, PHONE_INTL, TEL, WHATSAPP, INSTAGRAM, INSTAGRAM_WEB, TIKTOK, FACEBOOK } from './contact'
import { INSPECT_FULL } from './inspection'
// the stores' Google Maps links (2026-11-14, the client's team): the About and warranty pages link them, STORES_LD names them
const MAP_ARKADIA = 'https://maps.app.goo.gl/jA9owX6N3L6bCpXP8'
const MAP_HELIOPOLIS = 'https://maps.app.goo.gl/c5wtDgsmLEP398ht7'
// re-exported (2026-10-19) for the 500 page's "Contact us on WhatsApp"
export { WHATSAPP }
const EMAIL = 'Info@brandfashionegy.com'
const MAILTO = `mailto:${EMAIL}`

export const TRUST_PAGES = {
  about: {
    path: '/about-us',
    en: {
      title: 'About Brand Fashion',
      // The old site's Rank Math title and description (D-14, 2026-11-11), the old "BrandFashion" written "Brand Fashion", as the shop writes its name.
      metaTitle: 'About Us | Brand Fashion the best Watches Store in Egypt',
      description: "Learn more about Brand Fashion, Egypt's top destination for original watches, accessories, and fashion items. Order now for fast delivery.",
      lead: 'Brand Fashion has sold watches, bags and accessories in Egypt since 2013, online and in two stores in Cairo. We sell watches for men and women, along with bags, wallets, belts, jewellery and perfumes, and a small range of electronics, and we deliver to 27 governorates.',
      store: `Shop here in English or Arabic, or visit one of our two stores, open every day 10:00–24:00: [Arkadia Mall](${MAP_ARKADIA}), 2nd floor, Corniche El Nil, Maspero extension, next to the Fairmont, Cairo, and [90 El-Thawra Street](${MAP_HELIOPOLIS}), Heliopolis. Call or WhatsApp us on [${PHONE}](${TEL}).`,
      // sister stores (Michael, 2026-11-17; the client approves saying it): owned by Elegance, the two branches shared, each
      // shop with its own number and catalogue — only the three names, nothing else about the owners
      family: 'Brand Fashion and Watchizer are sister stores, both owned by Elegance. They share the two branches, Arkadia Mall and Heliopolis; each shop has its own phone number and its own catalogue. Visit Watchizer at [watchizereg.com](https://watchizereg.com).',
      // the shop's followers (settings.public.followers, a dashboard setting): {followers} is the rounded figure; no figure, no line
      followers: `{followers} people follow Brand Fashion on [Facebook](${FACEBOOK}), [Instagram](${INSTAGRAM}) and [TikTok](${TIKTOK}).`,
      policies: 'How we work: the [warranty](/warranty), [shipping and delivery](/shipping), [returns and exchanges](/returns), and the [questions we are asked most](/faq).',
      service: "We confirm every order with you before it ships, and you pay in cash when it arrives. " + INSPECT_FULL.en + " If anything isn't right, our customer service team is on [WhatsApp](" + WHATSAPP + ').',
    },
    ar: {
      title: 'من نحن',
      metaTitle: 'من نحن | براند فاشون',
      description: 'براند فاشون متجر إلكتروني للساعات والحقائب والإكسسوارات في مصر، ونوصّل إلى ٢٧ محافظة.',
      lead: 'تبيع براند فاشون الساعات والحقائب والإكسسوارات في مصر منذ ٢٠١٣، أونلاين وفي متجرين في القاهرة. نبيع ساعات رجالية ونسائية، إلى جانب الحقائب والمحافظ والأحزمة والمجوهرات والعطور ومجموعة صغيرة من الإلكترونيات، ونوصّل إلى ٢٧ محافظة.',
      store: `تسوّق هنا بالعربية أو الإنجليزية، أو زر أحد متجرينا، المفتوحين يومياً من ١٠ صباحاً حتى منتصف الليل: [أركاديا مول](${MAP_ARKADIA})، الدور الثاني، كورنيش النيل، امتداد ماسبيرو، بجوار فندق فيرمونت، القاهرة، و[٩٠ شارع الثورة](${MAP_HELIOPOLIS})، مصر الجديدة. اتصل بنا أو راسلنا على واتساب: [${PHONE}](${TEL}).`,
      family: 'براند فاشون وواتشايزر متجران شقيقان تملكهما شركة Elegance، ويتشاركان الفرعين، أركاديا مول ومصر الجديدة؛ ولكل متجر رقم هاتفه الخاص وتشكيلته الخاصة. زر واتشايزر على [watchizereg.com](https://watchizereg.com).',
      followers: `يتابع براند فاشون {followers} شخص على [فيسبوك](${FACEBOOK}) و[إنستجرام](${INSTAGRAM}) و[تيك توك](${TIKTOK}).`,
      policies: 'كيف نعمل: [الضمان](/warranty)، و[الشحن والتوصيل](/shipping)، و[الإرجاع والاستبدال](/returns)، و[الأسئلة الشائعة](/faq).',
      service: 'نؤكد كل طلب معك قبل شحنه، وتدفع نقداً عند الاستلام. ' + INSPECT_FULL.ar + ' وإن كان هناك أي خطأ، فريق خدمة العملاء متاح على [واتساب](' + WHATSAPP + ').',
    },
  },

  // The client's confirmed details: phone and WhatsApp 01014179820 (2026-11-14, replacing the old number),
  // Info@brandfashionegy.com, Instagram brand_fashion_eg and brand_fashion_web, and since 2026-11-14 Facebook and TikTok
  // (the client's team). Opening hours: every day 10:00 to midnight (Michael, 2026-10-29).
  // 2026-10-19 (Michael): the same two branches as Watchizer — Arcadia Mall and Heliopolis, 90 El-Thawra Street.
  contact: {
    path: '/contact-us',
    en: {
      title: 'Contact us',
      // The old site's Rank Math text (D-14): its title template "%title% %sep% %sitename% in Egypt", expanded.
      metaTitle: 'Contact us - Brand Fashion in Egypt',
      description: 'Contact us - Arkadia Mall, 2nd floor, Corniche El Nil, Maspero extension, next to the Fairmont, Cairo.',
      group: 'Customer service (phone and WhatsApp)',
      hours: '',
      // the STORES' hours (Michael, 2026-10-29: the client's figure, both branches); customer service has none given
      storeHours: 'Every day, 10:00–24:00.',
      cards: [
        { icon: 'phone', label: 'Phone', value: PHONE, href: TEL, ltr: true },
        { icon: 'whatsapp', label: 'WhatsApp', value: PHONE, href: WHATSAPP, ltr: true },
        { icon: 'mail', label: 'Email', value: EMAIL, href: MAILTO, ltr: true },
        { icon: 'instagram', label: 'Instagram', value: '@brand_fashion_eg', href: INSTAGRAM, ltr: true },
        { icon: 'instagram', label: 'Instagram', value: '@brand_fashion_web', href: INSTAGRAM_WEB, ltr: true },
        { icon: 'facebook', label: 'Facebook', value: 'Brand Fashion', href: FACEBOOK, ltr: true },
        { icon: 'tiktok', label: 'TikTok', value: '@brand_fashion_egy', href: TIKTOK, ltr: true },
      ],
      stores: [
        { label: 'Arkadia Mall branch', address: 'Arkadia Mall, 2nd floor, Corniche El Nil, Maspero extension, next to the Fairmont, Cairo.', hours: true },
        { label: 'Heliopolis branch', address: '90 El-Thawra Street, Heliopolis.', hours: true },
      ],
      note: "If your question is about an order, have your order number ready. You'll find it in your confirmation email and under [*My Orders*](/account?tab=orders).",
    },
    ar: {
      title: 'تواصل معنا',
      metaTitle: 'تواصل معنا | براند فاشون',
      description: `خدمة عملاء براند فاشون: هاتف وواتساب ${PHONE}. البريد ${EMAIL}.`,
      group: 'خدمة العملاء (هاتف وواتساب)',
      hours: '',
      storeHours: 'يومياً من ١٠ صباحاً حتى منتصف الليل.',
      cards: [
        { icon: 'phone', label: 'هاتف', value: PHONE, href: TEL, ltr: true },
        { icon: 'whatsapp', label: 'واتساب', value: PHONE, href: WHATSAPP, ltr: true },
        { icon: 'mail', label: 'البريد الإلكتروني', value: EMAIL, href: MAILTO, ltr: true },
        { icon: 'instagram', label: 'إنستجرام', value: '@brand_fashion_eg', href: INSTAGRAM, ltr: true },
        { icon: 'instagram', label: 'إنستجرام', value: '@brand_fashion_web', href: INSTAGRAM_WEB, ltr: true },
        { icon: 'facebook', label: 'فيسبوك', value: 'Brand Fashion', href: FACEBOOK, ltr: true },
        { icon: 'tiktok', label: 'تيك توك', value: '@brand_fashion_egy', href: TIKTOK, ltr: true },
      ],
      stores: [
        { label: 'فرع أركاديا مول', address: 'أركاديا مول، كورنيش النيل، امتداد ماسبيرو، بجوار فندق فيرمونت، الدور الثاني، القاهرة.', hours: true },
        { label: 'فرع مصر الجديدة', address: '٩٠ شارع الثورة، مصر الجديدة.', hours: true },
      ],
      note: 'إن كان سؤالك عن طلب، جهّز رقم الطلب. تجده في رسالة التأكيد وفي [«طلباتي»](/account?tab=orders).',
    },
  },

  // DRAFT (round 5) — Watchizer's approved policy with Brand Fashion's facts: no card payments and no Paymob
  // (storefront 2 offers cash on delivery only), Meta as the only tracker (D8 — no TikTok).
  privacy: {
    path: '/privacy-policy',
    updated: true,
    en: {
      title: 'Privacy policy',
      // The old site's Rank Math text (D-14), its "Privcay" typo fixed.
      metaTitle: 'Privacy Policy | Brand Fashion',
      description: 'Learn how Brand Fashion ensures your privacy and protects your personal data. Review our privacy policy for all details.',
      sections: [
        {
          title: 'What we collect, and why',
          blocks: [
            {
              ul: [
                // the Google sentence only while this storefront runs Google sign-in (L3, 2026-10-19 — TrustPage)
                { text: '**Your account.** We keep your name, email, phone number and password; the password is stored encrypted. We use them to sign you in and show your orders.', withGoogle: ' You can also sign in with Google.' },
                '**Your orders.** We keep your delivery address, phone number and the items you buy. We use them to deliver and to handle exchanges and returns. We keep order records for as long as we need them for that and for our accounts.',
                "**“Email me when it’s back”.** We keep the address you leave and use it only for that product. The email has a link to stop it. We delete the request 30 days after we email you, or after 180 days if the product doesn't come back.",
                "**Emails about new products.** If you have an account and haven't visited for a while, we may email you a few new products, at most once a week. Every email has an unsubscribe link, and unsubscribing is permanent.",
              ],
            },
          ],
        },
        {
          title: 'Cookies and similar storage',
          blocks: [{ p: "We store your language and your cart so the site remembers them. We use the Meta (Facebook) pixel to measure our adverts. When you place an order, our server also sends Meta the order's value and items, for the same purpose." }],
        },
        {
          title: 'Who we share it with',
          blocks: [
            { p: 'Only what each one needs:' },
            { ul: ['our delivery partner: your name, address and phone number;', 'Meta: advert measurement, as above;', 'Cloudflare: Turnstile, an invisible check that protects our review form from automated reviews ([Turnstile Privacy Addendum](https://www.cloudflare.com/turnstile-privacy-policy/));', 'our email provider.'] },
            { p: 'We do not sell your data.' },
          ],
        },
        {
          title: 'Your choices',
          blocks: [{ p: `You can see and change your details under [*My Account*](/account?tab=profile). To delete your account, or to ask what we hold about you, email [${EMAIL}](${MAILTO}) or message us on WhatsApp at [${PHONE}](${WHATSAPP}).` }],
        },
      ],
    },
    ar: {
      title: 'سياسة الخصوصية',
      metaTitle: 'سياسة الخصوصية | براند فاشون',
      description: 'ما تجمعه براند فاشون من بيانات ولماذا، ومع من نشاركها، واختياراتك.',
      sections: [
        {
          title: 'ما نجمعه ولماذا',
          blocks: [
            {
              ul: [
                { text: '**حسابك.** نحفظ اسمك وبريدك الإلكتروني ورقم هاتفك وكلمة المرور، وكلمة المرور محفوظة مشفّرة. نستخدمها لتسجيل دخولك وعرض طلباتك.', withGoogle: ' يمكنك أيضاً تسجيل الدخول بحساب جوجل.' },
                '**طلباتك.** نحفظ عنوان التوصيل ورقم الهاتف والمنتجات التي تشتريها. نستخدمها للتوصيل وللاستبدال والإرجاع. نحتفظ بسجلات الطلبات طالما احتجنا إليها لذلك ولحساباتنا.',
                '**«أبلغني عند التوفر».** نحفظ البريد الذي تتركه ونستخدمه لهذا المنتج فقط. في الرسالة رابط لإيقافها. نحذف الطلب بعد ٣٠ يوماً من مراسلتك، أو بعد ١٨٠ يوماً إن لم يعد المنتج متوفراً.',
                '**رسائل المنتجات الجديدة.** إن كان لديك حساب ولم تزر الموقع منذ فترة، قد نرسل إليك بعض المنتجات الجديدة، مرة في الأسبوع على الأكثر. في كل رسالة رابط لإلغاء الاشتراك، والإلغاء نهائي.',
              ],
            },
          ],
        },
        {
          title: 'ملفات تعريف الارتباط والتخزين المشابه',
          blocks: [{ p: 'نحفظ لغتك وسلتك ليتذكرهما الموقع. نستخدم بكسل ميتا (فيسبوك) لقياس إعلاناتنا. وعند إتمام الطلب يرسل خادمنا أيضاً إلى ميتا قيمة الطلب ومنتجاته، للغرض نفسه.' }],
        },
        {
          title: 'مع من نشاركها',
          blocks: [
            { p: 'فقط ما يحتاجه كل طرف:' },
            { ul: ['شريك التوصيل: الاسم والعنوان ورقم الهاتف؛', 'ميتا: قياس الإعلانات كما سبق؛', 'Cloudflare: خدمة Turnstile، فحص غير مرئي يحمي نموذج التقييمات من التقييمات الآلية ([ملحق خصوصية Turnstile](https://www.cloudflare.com/turnstile-privacy-policy/))؛', 'مزوّد البريد الإلكتروني.'] },
            { p: 'لا نبيع بياناتك.' },
          ],
        },
        {
          title: 'اختياراتك',
          blocks: [{ p: `يمكنك مراجعة بياناتك وتعديلها من [«حسابي»](/account?tab=profile). لحذف حسابك أو لمعرفة ما نحتفظ به عنك، راسلنا على [${EMAIL}](${MAILTO}) أو على واتساب [${PHONE}](${WHATSAPP}).` }],
        },
      ],
    },
  },

  // Watchizer's approved terms AS THEY STAND (Michael, round 5: the returns policy is Watchizer's — it replaces the
  // text on Brand Fashion's live site), with the shop's name; Payment limited to cash on delivery, storefront 2's
  // only method today.
  terms: {
    path: '/terms-and-conditions',
    updated: true,
    en: {
      title: 'Terms and conditions',
      metaTitle: 'Terms and conditions | Brand Fashion',
      description: 'Prices, orders, payment, delivery, exchanges and returns, authenticity and warranty at Brand Fashion.',
      intro: 'These terms apply to every order placed on this site. "We" and "Brand Fashion" mean the shop; "you" means the customer.',
      sections: [
        { title: 'Prices', blocks: [{ p: 'Prices are in Egyptian pounds. A price can change until you place your order; the price shown at checkout is the price you pay.' }] },
        { title: 'Orders', blocks: [{ p: 'Your order is accepted when we confirm it with you. If an item turns out to be unavailable, we tell you and refund anything you have already paid.' }] },
        { title: 'Payment', blocks: [{ p: 'You pay in cash when your order is delivered.' }, { p: INSPECT_FULL.en }] },
        {
          title: 'Delivery',
          blocks: [
            { p: 'We deliver to 27 governorates. The delivery fee depends on your governorate and is shown at checkout. Each product page shows how the item ships:' },
            { ul: ['**Express** items are delivered in 2–5 business days;', '**Market** items are delivered in 4–7 business days.'] },
            { p: 'An order with both kinds may arrive in more than one delivery.' },
          ],
        },
        {
          title: 'Exchanges and returns',
          blocks: [
            // the returns policy of 2026-11-14 (approved by the client): 14 days to return or exchange, every product; the shop
            // pays the return shipping; the refund by the original method, within 24 hours of the item reaching us
            { ul: ['**Return:** within 14 days of delivery — watches and fashion items alike.', '**Exchange:** within 14 days of delivery.'] },
            { p: `Watches, bags and accessories must be unused, in their original packaging with their tags; perfumes must be sealed and unopened. We pay the return shipping. Your refund is made by the payment method you used, as fast as possible and within 24 hours of the item reaching us. To start an exchange or return, message customer service on [WhatsApp](${WHATSAPP}) with your order number, and we'll arrange it with you.` },
          ],
        },
        /*
         * Authenticity (2026-10-19, the developer: "YES, the shop does guarantee the authenticity of what it sells").
         * The shop has always CLAIMED it — "100% authentic" at the cart and the checkout, "Authenticity Guarantee" on
         * the product page, "certified & guaranteed" in every search snippet — while the policy pages said nothing,
         * which is how the claim read as unbacked. The wording is the claim itself and no more: the guarantee, then
         * the way to raise it, in the voice the exchanges section already uses. No remedy is invented here.
         */
        {
          title: 'Authenticity',
          blocks: [
            { p: `Every product we sell is authentic. If you have any doubt about an item you received, message customer service on [WhatsApp](${WHATSAPP}) with your order number.` },
          ],
        },
        { title: 'Warranty', blocks: [{ p: 'When a product page states a warranty, it applies as stated there. Contact customer service to use it.' }] },
        { title: 'Your account', blocks: [{ p: 'Keep your password to yourself. You are responsible for orders placed from your account.' }] },
        { title: 'Changes', blocks: [{ p: 'We may update these terms. The version published when you place an order applies to that order.' }] },
        { title: 'Law', blocks: [{ p: 'These terms are governed by the laws of Egypt.' }] },
      ],
    },
    ar: {
      title: 'الشروط والأحكام',
      metaTitle: 'الشروط والأحكام | براند فاشون',
      description: 'الأسعار والطلبات والدفع والتوصيل والاستبدال والإرجاع والأصالة والضمان في براند فاشون.',
      intro: 'تنطبق هذه الشروط على كل طلب يتم عبر هذا الموقع. «نحن» و«براند فاشون» تعني المتجر، و«أنت» تعني العميل.',
      sections: [
        { title: 'الأسعار', blocks: [{ p: 'الأسعار بالجنيه المصري. قد يتغير السعر حتى تتم طلبك؛ السعر المعروض عند إتمام الطلب هو ما تدفعه.' }] },
        { title: 'الطلبات', blocks: [{ p: 'يُقبل طلبك عندما نؤكده معك. إن تبيّن أن منتجاً غير متوفر، نبلغك ونرد لك أي مبلغ دفعته.' }] },
        { title: 'الدفع', blocks: [{ p: 'تدفع نقداً عند استلام طلبك.' }, { p: INSPECT_FULL.ar }] },
        {
          title: 'التوصيل',
          blocks: [
            { p: 'نوصّل إلى ٢٧ محافظة. رسوم التوصيل حسب محافظتك وتظهر عند إتمام الطلب. تعرض صفحة كل منتج طريقة شحنه:' },
            { ul: ['منتجات **إكسبريس** تصل خلال ٢–٥ أيام عمل؛', 'منتجات **ماركت** تصل خلال ٤–٧ أيام عمل.'] },
            { p: 'الطلب الذي يجمع النوعين قد يصل على أكثر من دفعة.' },
          ],
        },
        {
          title: 'الاستبدال والإرجاع',
          blocks: [
            { ul: ['**الإرجاع:** خلال ١٤ يوماً من الاستلام — للساعات ومنتجات الأزياء على حد سواء.', '**الاستبدال:** خلال ١٤ يوماً من الاستلام.'] },
            { p: `يجب أن تكون الساعات والشنط والإكسسوارات غير مستخدمة، في عبوتها الأصلية ومعها بطاقاتها؛ أما العطور فيجب أن تكون مغلقة لم تُفتح. نتحمّل نحن شحن الإرجاع، ويُرد المبلغ بوسيلة الدفع نفسها التي استخدمتها في أسرع وقت، وخلال ٢٤ ساعة من وصول المنتج إلينا. لبدء الاستبدال أو الإرجاع، راسل خدمة العملاء على [واتساب](${WHATSAPP}) برقم طلبك، وسنرتب الأمر معك.` },
          ],
        },
        {
          title: 'الأصالة',
          blocks: [
            { p: `كل منتج نبيعه أصلي. إن كان لديك أي شك في منتج استلمته، راسل خدمة العملاء على [واتساب](${WHATSAPP}) برقم طلبك.` },
          ],
        },
        { title: 'الضمان', blocks: [{ p: 'عندما تذكر صفحة المنتج ضماناً، فهو يسري كما هو مذكور فيها. تواصل مع خدمة العملاء للاستفادة منه.' }] },
        { title: 'حسابك', blocks: [{ p: 'احتفظ بكلمة المرور لنفسك. أنت مسؤول عن الطلبات التي تتم من حسابك.' }] },
        { title: 'التعديلات', blocks: [{ p: 'قد نحدّث هذه الشروط. تنطبق على كل طلب النسخة المنشورة وقت إتمامه.' }] },
        { title: 'القانون', blocks: [{ p: 'تخضع هذه الشروط للقوانين المصرية.' }] },
      ],
    },
  },
  // ── WARRANTY, SHIPPING, RETURNS (SEO Phase 1, 2026-11-14) ────────────────────────────────────────────────────────────
  // Michael's trust-pages input of 2026-11-14 (new branding/docs/seo/TRUST_PAGES_INPUT_2026-11-14.md): state plainly and
  // ONLY what is true. Every sentence below is one the shop already states — the FAQ (the client's facts of 2026-10-29),
  // the terms above (the returns policy of 2026-11-14, delivery, payment) and core's warranty rule
  // (App\Storefront\Warranty); Brand Fashion's own FAQ for what it covers — gathered on one page each, in the same words where they exist. No dealer or agent claim.
  // NOT stated, for want of the facts (waiting for Michael): how long a repair takes, and what the warranty excludes
  // beyond accidents. Delivery: "24 hours", the brief's BUSINESS FACT 5 (the FAQ and /llms.txt say the same); the
  // terms' older Express 2–5 / Market 4–7 business days are Michael's to align.
  warranty: {
    path: '/warranty',
    en: {
      title: 'Warranty',
      metaTitle: 'Watch warranty | Brand Fashion',
      description: "Up to 3 years by watch, the brand agent's or the store's, on the movement against manufacturing defects, with free battery changes and bracelet sizing.",
      intro: 'What the warranty on a watch from Brand Fashion covers, how long it lasts, and how to use it.',
      sections: [
        { title: 'Who gives it', blocks: [{ p: "Most of our watches carry their **brand agent's warranty**; a few carry Brand Fashion's own **store warranty**. Each watch's product page and warranty card say which, and for how long. Either way, you start at one of our stores or on WhatsApp." }] },
        {
          title: 'How long it lasts',
          blocks: [
            { p: '**Up to 3 years, depending on the watch.** The length for each watch is on its product page and on its warranty card.' },
            { p: 'Other items — bags, wallets, belts, jewellery, perfumes and electronics — carry a warranty only when their product page states one.' },
          ],
        },
        {
          title: 'What it covers',
          blocks: [
            { p: 'Manufacturing defects in materials and workmanship of the **movement and its parts** (for most brands: the movement, the hands and the dial), under normal use.' },
            { p: 'Within the warranty, our stores also **replace the battery and size the bracelet free of charge**.' },
          ],
        },
        {
          title: 'What it does not cover',
          blocks: [
            { ul: ['The battery itself, the strap or bracelet, the glass (crystal), and the case and its plating.', "Water damage when the watch's water-resistance rating and care instructions were not followed.", 'Damage from accidents, misuse or lack of care, and normal wear such as scratches.', "A watch opened or repaired by anyone other than the brand's approved service."] },
            { p: 'Work outside the warranty is done for a fee, quoted before we start.' },
          ],
        },
        { title: 'How long a repair takes', blocks: [{ p: 'It depends on the watch and on whether parts are needed, so we do not promise a fixed time. We keep you informed at every step, and tell you as soon as your watch is ready.' }] },
        {
          title: 'How to use it',
          blocks: [
            { p: 'Bring the watch to one of our stores with its **warranty card and invoice**:' },
            { ul: [`[Arkadia Mall](${MAP_ARKADIA}), 2nd floor, Corniche El Nil, Maspero extension, next to the Fairmont, Cairo;`, `[90 El-Thawra Street](${MAP_HELIOPOLIS}), Heliopolis.`] },
            { p: `Both are open every day, 10:00–24:00. If you cannot come to a store, message customer service on [WhatsApp](${WHATSAPP}) with your order number.` },
          ],
        },
      ],
    },
    ar: {
      title: 'الضمان',
      metaTitle: 'ضمان الساعات | براند فاشون',
      description: 'حتى ٣ سنوات حسب الساعة، ضمان الوكيل أو ضمان المتجر، على الماكينة ضد عيوب التصنيع، مع تغيير البطارية وضبط مقاس السوار مجاناً.',
      intro: 'ما يغطيه ضمان الساعة من براند فاشون، ومدته، وكيف تستفيد منه.',
      sections: [
        { title: 'من يقدّم الضمان', blocks: [{ p: 'تحمل معظم ساعاتنا **ضمان وكيل الماركة**، وبعضها يحمل **ضمان المتجر** من براند فاشون. وتوضح صفحة كل ساعة وبطاقة ضمانها أي الضمانين لها ومدته. وفي الحالتين تبدأ من أحد متجرينا أو على واتساب.' }] },
        {
          title: 'مدة الضمان',
          blocks: [
            { p: '**حتى ٣ سنوات حسب الساعة.** ومدة كل ساعة مذكورة في صفحتها وفي بطاقة ضمانها.' },
            { p: 'أما المنتجات الأخرى — الحقائب والمحافظ والأحزمة والمجوهرات والعطور والإلكترونيات — فلها ضمان فقط إذا ذكرته صفحتها.' },
          ],
        },
        {
          title: 'ما يغطيه الضمان',
          blocks: [
            { p: 'عيوب التصنيع في المواد والصنعة في **الماكينة وأجزائها** (لدى معظم الماركات: الماكينة والعقارب والمينا)، مع الاستخدام الطبيعي.' },
            { p: 'وخلال فترة الضمان، **نغيّر البطارية ونضبط مقاس السوار مجاناً** في متجرينا.' },
          ],
        },
        {
          title: 'ما لا يغطيه الضمان',
          blocks: [
            { ul: ['البطارية نفسها، والسوار، والزجاج (الكريستال)، والعلبة وطلاؤها.', 'أضرار الماء إذا لم تُراعَ درجة مقاومة الساعة للماء وتعليمات العناية بها.', 'أضرار الحوادث أو سوء الاستخدام أو قلة العناية، والاستهلاك الطبيعي مثل الخدوش.', 'الساعة التي فتحها أو أصلحها غير مركز صيانة معتمد.'] },
            { p: 'الأعمال خارج الضمان تتم بمقابل، نخبرك به قبل أن نبدأ.' },
          ],
        },
        { title: 'مدة الإصلاح', blocks: [{ p: 'تختلف حسب الساعة وحسب الحاجة إلى قطع غيار، لذلك لا نعد بمدة محددة. نبقيك على اطلاع في كل خطوة، ونخبرك فور أن تصبح ساعتك جاهزة.' }] },
        {
          title: 'كيف تستفيد منه',
          blocks: [
            { p: 'أحضر الساعة إلى أحد متجرينا ومعها **بطاقة الضمان والفاتورة**:' },
            { ul: [`[أركاديا مول](${MAP_ARKADIA})، الدور الثاني، كورنيش النيل، امتداد ماسبيرو، بجوار فندق فيرمونت، القاهرة؛`, `[٩٠ شارع الثورة](${MAP_HELIOPOLIS})، مصر الجديدة.`] },
            { p: `المتجران مفتوحان يومياً من ١٠ صباحاً حتى منتصف الليل. وإن لم تتمكن من الحضور، راسل خدمة العملاء على [واتساب](${WHATSAPP}) برقم طلبك.` },
          ],
        },
      ],
    },
  },

  shipping: {
    path: '/shipping',
    en: {
      title: 'Shipping and delivery',
      metaTitle: 'Shipping and delivery in Egypt | Brand Fashion',
      description: 'Delivery to all 27 governorates of Egypt within 24 hours. The fee depends on the governorate and shows before you pay.',
      intro: 'Where we deliver, how long it takes, what it costs and how you pay.',
      sections: [
        { title: 'Where we deliver', blocks: [{ p: 'To all 27 governorates of Egypt.' }] },
        { title: 'How long it takes', blocks: [{ p: 'We deliver to every governorate **within 24 hours**.' }] },
        { title: 'What it costs', blocks: [{ p: 'The fee depends on your governorate. The cart shows it before you pay, as soon as you choose where to deliver.' }] },
        { title: 'Before it ships', blocks: [{ p: 'We confirm every order with you before it ships.' }] },
        {
          title: 'Paying',
          blocks: [
            { p: 'You pay in cash when your order is delivered.' },
            { p: INSPECT_FULL.en },
          ],
        },
        { title: 'Returns', blocks: [{ p: 'You have 14 days to return or exchange an item, and we pay the return shipping: see [returns and exchanges](/returns).' }] },
      ],
    },
    ar: {
      title: 'الشحن والتوصيل',
      metaTitle: 'الشحن والتوصيل في مصر | براند فاشون',
      description: 'نوصّل إلى ٢٧ محافظة في مصر خلال ٢٤ ساعة. الرسوم حسب المحافظة وتظهر قبل الدفع.',
      intro: 'أين نوصّل، وكم يستغرق التوصيل، وكم يكلّف، وكيف تدفع.',
      sections: [
        { title: 'أين نوصّل', blocks: [{ p: 'إلى ٢٧ محافظة في مصر.' }] },
        { title: 'مدة التوصيل', blocks: [{ p: 'نوصّل إلى كل المحافظات **خلال ٢٤ ساعة**.' }] },
        { title: 'التكلفة', blocks: [{ p: 'تختلف رسوم التوصيل حسب محافظتك، وتظهر في السلة قبل الدفع بمجرد اختيار مكان التوصيل.' }] },
        { title: 'قبل الشحن', blocks: [{ p: 'نؤكد كل طلب معك قبل شحنه.' }] },
        {
          title: 'الدفع',
          blocks: [
            { p: 'تدفع نقداً عند استلام طلبك.' },
            { p: INSPECT_FULL.ar },
          ],
        },
        { title: 'الإرجاع', blocks: [{ p: 'لديك ١٤ يوماً لإرجاع المنتج أو استبداله، ونتحمّل نحن شحن الإرجاع: اقرأ [الإرجاع والاستبدال](/returns).' }] },
      ],
    },
  },

  returns: {
    path: '/returns',
    en: {
      title: 'Returns and exchanges',
      metaTitle: 'Returns and exchanges | Brand Fashion',
      description: 'Return or exchange any item within 14 days of delivery. We pay the return shipping and refund within 24 hours of the item reaching us.',
      intro: 'Our returns policy, for watches and fashion items alike.',
      sections: [
        { title: 'How long you have', blocks: [{ ul: ['**Return:** within 14 days of delivery — watches and fashion items alike.', '**Exchange:** within 14 days of delivery.'] }] },
        { title: 'The condition of the item', blocks: [{ p: 'Watches, bags and accessories must be unused, in their original packaging with their tags; perfumes must be sealed and unopened.' }] },
        { title: 'Return shipping', blocks: [{ p: '**We pay the return shipping.**' }] },
        { title: 'Your refund', blocks: [{ p: 'Your refund is made by the payment method you used, as fast as possible and within 24 hours of the item reaching us.' }] },
        { title: 'How to start', blocks: [{ p: `Message customer service on [WhatsApp](${WHATSAPP}) with your order number, and we'll arrange it with you.` }] },
      ],
    },
    ar: {
      title: 'الإرجاع والاستبدال',
      metaTitle: 'الإرجاع والاستبدال | براند فاشون',
      description: 'أرجع أو استبدل أي منتج خلال ١٤ يوماً من الاستلام. نتحمّل نحن شحن الإرجاع، ويُرد المبلغ خلال ٢٤ ساعة من وصول المنتج إلينا.',
      intro: 'سياسة الإرجاع لدينا، للساعات ومنتجات الأزياء على حد سواء.',
      sections: [
        { title: 'المدة المتاحة', blocks: [{ ul: ['**الإرجاع:** خلال ١٤ يوماً من الاستلام — للساعات ومنتجات الأزياء على حد سواء.', '**الاستبدال:** خلال ١٤ يوماً من الاستلام.'] }] },
        { title: 'حالة المنتج', blocks: [{ p: 'يجب أن تكون الساعات والشنط والإكسسوارات غير مستخدمة، في عبوتها الأصلية ومعها بطاقاتها؛ أما العطور فيجب أن تكون مغلقة لم تُفتح.' }] },
        { title: 'شحن الإرجاع', blocks: [{ p: '**نتحمّل نحن شحن الإرجاع.**' }] },
        { title: 'استرداد المبلغ', blocks: [{ p: 'يُرد المبلغ بوسيلة الدفع نفسها التي استخدمتها في أسرع وقت، وخلال ٢٤ ساعة من وصول المنتج إلينا.' }] },
        { title: 'كيف تبدأ', blocks: [{ p: `راسل خدمة العملاء على [واتساب](${WHATSAPP}) برقم طلبك، وسنرتّب الأمر معك.` }] },
      ],
    },
  },
  // ── THE TEAM (AUTHOR) PAGE AND THE EDITORIAL POLICY (SEO Phase 3, 2026-11-14) ─────────────────────────────────────────
  // The brief's BUSINESS FACTS 8 and 10: articles are signed by the shop's team, never a person (no invented names); the
  // author page says who we are, what we sell, how products are checked, how articles are written and checked, and how
  // to reach us. NOTHING here claims human review or editing: articles are written with the help of AI tools, product
  // facts are checked AUTOMATICALLY against the live catalogue, other facts come from cited sources. Every fact is one
  // the shop already states (the About page, the inspection rule, the warranty page).
  team: {
    path: '/team',
    en: {
      title: 'Brand Fashion Team',
      metaTitle: 'Brand Fashion Team | Brand Fashion',
      description: 'Who writes the Brand Fashion guides: the shop team, selling watches, bags and accessories in Egypt since 2013, online and in two Cairo stores.',
      intro: 'The articles and buying guides on brandfashionegy.com are signed "Brand Fashion Team": the shop itself, not one person.',
      sections: [
        { title: 'Who we are', blocks: [{ p: `Brand Fashion has sold watches, bags and accessories in Egypt since 2013, online and in two stores in Cairo: [Arkadia Mall](${MAP_ARKADIA}) and [90 El-Thawra Street](${MAP_HELIOPOLIS}), Heliopolis.` }] },
        { title: 'What we sell', blocks: [{ p: 'We sell watches for men and women, along with bags, wallets, belts, jewellery, perfumes and a small range of electronics, and we deliver to 27 governorates. [See everything we carry](/listing).' }] },
        { title: 'How your order is checked', blocks: [{ p: 'We confirm every order with you before it ships. ' + INSPECT_FULL.en + ' The warranty on each watch is stated on its product page and its warranty card ([our warranty](/warranty)).' }] },
        {
          title: 'How our articles are written',
          blocks: [
            { p: 'Our articles are written by the shop team with the help of AI tools.' },
            { p: 'Product facts (model numbers, prices, how many models we carry, what is available) come from our live catalogue and are checked against it automatically before an article is published. Other facts come from the sources each article lists at its end.' },
            { p: 'The product lists inside an article are live: they show what we carry today. Read [our editorial policy](/editorial-policy).' },
          ],
        },
        { title: 'Contact us', blocks: [{ p: `Call or WhatsApp us on [${PHONE}](${TEL}), or email [${EMAIL}](${MAILTO}).` }] },
      ],
    },
    ar: {
      title: 'فريق براند فاشون',
      metaTitle: 'فريق براند فاشون | Brand Fashion',
      description: 'من يكتب أدلة براند فاشون: فريق متجر براند فاشون، الذي يبيع الساعات والحقائب والإكسسوارات في مصر منذ ٢٠١٣، أونلاين وفي متجرين في القاهرة.',
      intro: 'المقالات وأدلة الشراء على brandfashionegy.com موقّعة باسم "فريق براند فاشون": المتجر نفسه، لا شخص بعينه.',
      sections: [
        { title: 'من نحن', blocks: [{ p: `تبيع براند فاشون الساعات والحقائب والإكسسوارات في مصر منذ ٢٠١٣، أونلاين وفي متجرين في القاهرة: [أركاديا مول](${MAP_ARKADIA}) و[٩٠ شارع الثورة](${MAP_HELIOPOLIS})، مصر الجديدة.` }] },
        { title: 'ماذا نبيع', blocks: [{ p: 'نبيع ساعات رجالية ونسائية، إلى جانب الحقائب والمحافظ والأحزمة والمجوهرات والعطور ومجموعة صغيرة من الإلكترونيات، ونوصّل إلى ٢٧ محافظة. [شاهد كل ما نعرضه](/listing).' }] },
        { title: 'كيف يُراجَع طلبك', blocks: [{ p: 'نؤكد كل طلب معك قبل شحنه. ' + INSPECT_FULL.ar + ' ومدة الضمان على كل ساعة مذكورة في صفحتها وفي بطاقة ضمانها ([الضمان](/warranty)).' }] },
        {
          title: 'كيف نكتب مقالاتنا',
          blocks: [
            { p: 'يكتب مقالاتنا فريق المتجر بمساعدة أدوات الذكاء الاصطناعي.' },
            { p: 'معلومات المنتجات (أرقام الموديلات، والأسعار، وعدد الموديلات المتاحة لدينا، وما هو متوفر) مأخوذة من كتالوجنا المباشر، وتُطابَق معه آلياً قبل نشر أي مقال. أما المعلومات الأخرى فمصدرها المراجع التي يذكرها كل مقال في آخره.' },
            { p: 'قوائم المنتجات داخل المقال مباشرة: تعرض ما لدينا اليوم. اقرأ [سياستنا التحريرية](/editorial-policy).' },
          ],
        },
        { title: 'تواصل معنا', blocks: [{ p: `اتصل بنا أو راسلنا على واتساب: [${PHONE}](${TEL})، أو على البريد [${EMAIL}](${MAILTO}).` }] },
      ],
    },
  },
  editorial: {
    path: '/editorial-policy',
    en: {
      title: 'Editorial policy',
      metaTitle: 'Editorial policy | Brand Fashion',
      description: 'How the Brand Fashion articles are written, how their product facts are checked against our live catalogue, and how they are kept up to date.',
      intro: 'How the articles and buying guides on brandfashionegy.com are written, checked and kept up to date.',
      sections: [
        { title: 'Who writes them', blocks: [{ p: 'The shop team writes them with the help of AI tools, and they are signed "[Brand Fashion Team](/team)". We never invent an author.' }] },
        {
          title: 'How product facts are checked',
          blocks: [
            { p: 'Every model number and price an article names, and every count it gives, is checked automatically against our live catalogue before the article is published. An article that does not pass is not published.' },
            { p: 'Product lists inside an article are live: they show the products we carry today, available ones first. An item that is out of stock stays in the list, marked "currently unavailable", with a button to be told when it is back.' },
          ],
        },
        { title: 'Where other facts come from', blocks: [{ p: "Facts that do not come from our catalogue, such as a brand's history or how a movement works, come from the sources listed at the end of each article." }] },
        {
          title: 'How articles are kept up to date',
          blocks: [
            { p: 'When the catalogue changes in a way that matters to an article (models added or gone, prices moving), its product lists and figures update by themselves, and its "Last updated" date changes. A product going out of stock and back is not such a change.' },
            { p: 'An article whose text names a model we no longer carry, or a price that no longer matches, is flagged and rewritten, and the new version passes the same checks before it replaces the old one.' },
          ],
        },
        { title: 'Tell us about a mistake', blocks: [{ p: `Message us on [WhatsApp](${WHATSAPP}) with the article's address.` }] },
      ],
    },
    ar: {
      title: 'السياسة التحريرية',
      metaTitle: 'السياسة التحريرية | Brand Fashion',
      description: 'كيف تُكتب مقالات براند فاشون، وكيف تُطابَق معلومات منتجاتها مع كتالوجنا المباشر، وكيف تبقى محدّثة.',
      intro: 'كيف تُكتب المقالات وأدلة الشراء على brandfashionegy.com، وكيف تُراجَع آلياً، وكيف تبقى محدّثة.',
      sections: [
        { title: 'من يكتبها', blocks: [{ p: 'يكتبها فريق المتجر بمساعدة أدوات الذكاء الاصطناعي، وتُنشر باسم "[فريق براند فاشون](/team)". لا نخترع اسم كاتب أبداً.' }] },
        {
          title: 'كيف تُطابَق معلومات المنتجات',
          blocks: [
            { p: 'كل رقم موديل وكل سعر يذكره المقال، وكل عدد يورده، يُطابَق آلياً مع كتالوجنا المباشر قبل النشر. والمقال الذي لا يجتاز هذه المطابقة لا يُنشر.' },
            { p: 'قوائم المنتجات داخل المقال مباشرة: تعرض ما لدينا اليوم، والمتوفر أولاً. والمنتج غير المتوفر يبقى في القائمة مع علامة "غير متوفر حاليًا" وزر لإبلاغك عند عودته.' },
          ],
        },
        { title: 'مصدر المعلومات الأخرى', blocks: [{ p: 'المعلومات التي لا تأتي من كتالوجنا، مثل تاريخ علامة تجارية أو طريقة عمل الماكينة، مصدرها المراجع المذكورة في آخر كل مقال.' }] },
        {
          title: 'كيف تبقى المقالات محدّثة',
          blocks: [
            { p: 'حين يتغير الكتالوج تغيراً يهم المقال (موديلات تُضاف أو تختفي، أو أسعار تتحرك)، تتحدّث قوائم المنتجات والأرقام فيه تلقائياً، ويتغير تاريخ "آخر تحديث". أما نفاد منتج ثم عودته فليس تغييراً من هذا النوع.' },
            { p: 'والمقال الذي يذكر نصه موديلاً لم يعد لدينا، أو سعراً لم يعد صحيحاً، يُعلَّم ويُعاد كتابته، وتجتاز النسخة الجديدة المطابقة نفسها قبل أن تحل محل القديمة.' },
          ],
        },
        { title: 'أخبرنا عن خطأ', blocks: [{ p: `راسلنا على [واتساب](${WHATSAPP}) مع رابط المقال.` }] },
      ],
    },
  },
}

/*
 * THE STORES as structured data (Part 3 task 5, 2026-10-29) — the contact and about pages' Store + Organization
 * JSON-LD (src/lib/storesJsonLd.js) reads these, and they say exactly what the contact page above shows: two
 * branches, both open every day 10:00 to midnight (Michael, 2026-10-29: the client's hours, the ONLY policy-page
 * change; midnight written `closes: '23:59'`, Google's form), and the shop's own number, 01014179820 since
 * 2026-11-14 (./contact.js; every storefront keeps the number it shows). `contact.*.hours` and these change together.
 * `map` (2026-11-14, the client's team): each store's Google Maps link, as the store's hasMap.
 * No geo coordinates: none has been verified.
 */
export const STORES_LD = {
  // since 2013 (Michael, 2026-11-14): the Organization's foundingDate, and the About page's "since 2013"
  founded: '2013',
  name: 'Brand Fashion',
  // one line on what the shop sells, for /llms.txt (2026-11-14) — no claim about the products
  summary: 'An online shop and two stores in Cairo selling bags, perfumes, watches, sunglasses, wallets, belts and accessories, in English and Arabic.',
  phone: PHONE_INTL,
  whatsapp: WHATSAPP,
  email: EMAIL,
  logo: '/logo.svg',
  // the company that owns both shops, and the sister shop (2026-11-17): Organization.parentOrganization in the structured
  // data, one line in /llms.txt. Elegance has a name only: its domain serves the API, not a page.
  parent: 'Elegance',
  sister: { name: 'Watchizer', url: 'https://watchizereg.com' },
  family: 'Brand Fashion and Watchizer are sister stores owned by Elegance: they share the Arkadia Mall and Heliopolis branches, each shop with its own phone number and its own catalogue. Watchizer: https://watchizereg.com',
  sameAs: [FACEBOOK, INSTAGRAM, INSTAGRAM_WEB, TIKTOK],
  stores: [
    {
      key: 'arkadia',
      name: 'Brand Fashion — Arkadia Mall',
      address: { streetAddress: 'Arkadia Mall, 2nd floor, Corniche El Nil, Maspero extension, next to the Fairmont', addressLocality: 'Cairo', addressRegion: 'Cairo', addressCountry: 'EG' },
      map: MAP_ARKADIA,
      hours: { days: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], opens: '10:00', closes: '23:59' },
    },
    {
      key: 'heliopolis',
      name: 'Brand Fashion — Heliopolis',
      address: { streetAddress: '90 El-Thawra Street', addressLocality: 'Heliopolis', addressRegion: 'Cairo', addressCountry: 'EG' },
      map: MAP_HELIOPOLIS,
      hours: { days: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], opens: '10:00', closes: '23:59' },
    },
  ],
}
