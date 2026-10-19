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
export const TRUST_UPDATED = '2026-10-19'

const PHONE = '01283888490'
const TEL = 'tel:+201283888490'
// exported (2026-10-19) for the 500 page's "Contact us on WhatsApp" — one place for the support number
export const WHATSAPP = 'https://wa.me/201283888490'
const EMAIL = 'Info@brandfashionegy.com'
const MAILTO = `mailto:${EMAIL}`
const INSTAGRAM = 'https://www.instagram.com/brand_fashion_eg'

export const TRUST_PAGES = {
  about: {
    path: '/about-us',
    en: {
      title: 'About Brand Fashion',
      metaTitle: 'About us | Brand Fashion',
      description: 'Brand Fashion is an online shop for watches, bags and accessories in Egypt, delivering to 27 governorates.',
      lead: 'Brand Fashion is an online shop for watches, bags and accessories in Egypt. We sell watches for men and women, along with bags, wallets, belts, jewellery and perfumes, and a small range of electronics, and we deliver to 27 governorates.',
      store: 'Shop here in English or Arabic, or visit our store at Arcadia Mall, Corniche El Nil, Maspero extension, next to the Fairmont, 2nd floor, Cairo.',
      service: "We confirm every order with you before it ships, and you pay in cash when it arrives. If anything isn't right, our customer service team is on [WhatsApp](" + WHATSAPP + ').',
    },
    ar: {
      title: 'من نحن',
      metaTitle: 'من نحن | براند فاشون',
      description: 'براند فاشون متجر إلكتروني للساعات والحقائب والإكسسوارات في مصر، ونوصّل إلى ٢٧ محافظة.',
      lead: 'براند فاشون متجر إلكتروني للساعات والحقائب والإكسسوارات في مصر. نبيع ساعات رجالية ونسائية، إلى جانب الحقائب والمحافظ والأحزمة والمجوهرات والعطور ومجموعة صغيرة من الإلكترونيات، ونوصّل إلى ٢٧ محافظة.',
      store: 'تسوّق هنا بالعربية أو الإنجليزية، أو زر متجرنا في أركاديا مول، كورنيش النيل، امتداد ماسبيرو، بجوار فندق فيرمونت، الدور الثاني، القاهرة.',
      service: 'نؤكد كل طلب معك قبل شحنه، وتدفع نقداً عند الاستلام. وإن كان هناك أي خطأ، فريق خدمة العملاء متاح على [واتساب](' + WHATSAPP + ').',
    },
  },

  // The client's confirmed details (C-4): phone and WhatsApp 01283888490, Info@brandfashionegy.com, Arcadia
  // Mall, Instagram brand_fashion_eg. No Facebook (Michael, round 5). No opening hours: not confirmed.
  // 2026-10-19 (Michael): the same two branches as Watchizer — Arcadia Mall and Heliopolis, 90 El-Thawra Street.
  contact: {
    path: '/contact-us',
    en: {
      title: 'Contact us',
      metaTitle: 'Contact us | Brand Fashion',
      description: `Brand Fashion customer service: phone and WhatsApp ${PHONE}. Email ${EMAIL}.`,
      group: 'Customer service (phone and WhatsApp)',
      hours: '',
      cards: [
        { icon: 'phone', label: 'Phone', value: PHONE, href: TEL, ltr: true },
        { icon: 'whatsapp', label: 'WhatsApp', value: PHONE, href: WHATSAPP, ltr: true },
        { icon: 'mail', label: 'Email', value: EMAIL, href: MAILTO, ltr: true },
        { icon: 'instagram', label: 'Instagram', value: '@brand_fashion_eg', href: INSTAGRAM, ltr: true },
      ],
      stores: [
        { label: 'Arcadia Mall branch', address: 'Arcadia Mall, Corniche El Nil, Maspero extension, next to the Fairmont, 2nd floor, Cairo.' },
        { label: 'Heliopolis branch', address: '90 El-Thawra Street, Heliopolis.' },
      ],
      note: "If your question is about an order, have your order number ready. You'll find it in your confirmation email and under [*My Orders*](/account?tab=orders).",
    },
    ar: {
      title: 'تواصل معنا',
      metaTitle: 'تواصل معنا | براند فاشون',
      description: `خدمة عملاء براند فاشون: هاتف وواتساب ${PHONE}. البريد ${EMAIL}.`,
      group: 'خدمة العملاء (هاتف وواتساب)',
      hours: '',
      cards: [
        { icon: 'phone', label: 'هاتف', value: PHONE, href: TEL, ltr: true },
        { icon: 'whatsapp', label: 'واتساب', value: PHONE, href: WHATSAPP, ltr: true },
        { icon: 'mail', label: 'البريد الإلكتروني', value: EMAIL, href: MAILTO, ltr: true },
        { icon: 'instagram', label: 'إنستجرام', value: '@brand_fashion_eg', href: INSTAGRAM, ltr: true },
      ],
      stores: [
        { label: 'فرع أركاديا مول', address: 'أركاديا مول، كورنيش النيل، امتداد ماسبيرو، بجوار فندق فيرمونت، الدور الثاني، القاهرة.' },
        { label: 'فرع مصر الجديدة', address: '٩٠ شارع الثورة، مصر الجديدة.' },
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
      metaTitle: 'Privacy policy | Brand Fashion',
      description: 'What Brand Fashion collects, why, who it is shared with, and your choices.',
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
            { ul: ['our delivery partner: your name, address and phone number;', 'Meta: advert measurement, as above;', 'our email provider.'] },
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
            { ul: ['شريك التوصيل: الاسم والعنوان ورقم الهاتف؛', 'ميتا: قياس الإعلانات كما سبق؛', 'مزوّد البريد الإلكتروني.'] },
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
        { title: 'Payment', blocks: [{ p: 'You pay in cash when your order is delivered.' }] },
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
            { ul: ['**Watches:** return within 4 days of delivery, or exchange within 14 days.', '**Fashion items:** exchange or return within 4 days of delivery.'] },
            { p: `The item must be unused and in its original packaging. To start an exchange or return, message customer service on [WhatsApp](${WHATSAPP}) with your order number, and we'll arrange it with you.` },
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
        { title: 'الدفع', blocks: [{ p: 'تدفع نقداً عند استلام طلبك.' }] },
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
            { ul: ['**الساعات:** الإرجاع خلال ٤ أيام من الاستلام، أو الاستبدال خلال ١٤ يوماً.', '**منتجات الأزياء:** الاستبدال أو الإرجاع خلال ٤ أيام من الاستلام.'] },
            { p: `يجب أن يكون المنتج غير مستخدم وفي عبوته الأصلية. لبدء الاستبدال أو الإرجاع، راسل خدمة العملاء على [واتساب](${WHATSAPP}) برقم طلبك، وسنرتب الأمر معك.` },
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
}
