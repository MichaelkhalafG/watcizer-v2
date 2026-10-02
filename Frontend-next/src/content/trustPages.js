// The four trust pages — /about-us, /contact-us, /privacy-policy, /terms-and-conditions.
//
// The text is the developer-approved FINAL text of 2026-10-01, word for word, in both languages.
// Change it only with the developer's approval: it states the shop's policies (returns, delivery,
// payment, data), and the cart/checkout wordings that disagree with it are recorded as defects, not
// as the source of truth. Deliberately left out, by decision: anything about authenticity, the
// developer's phone number, a legal identity, the founding year, and a data-protection law.
//
// 2026-10-02 (design pass): the SAME words, arranged for the page designs — contact details as cards,
// the policies as numbered sections (a section's title is the bold lead of its paragraph in the
// approved text). Inline text supports **bold**, *italic* and [label](href), rendered as React
// elements by TrustPage, never as HTML. Every href here is one of the constants below.

// The date shown as "Last updated" on the privacy policy and the terms. Set it to the day the
// pages are first published (deploy day), and again whenever their text changes.
export const TRUST_UPDATED = '2026-10-02'

const PHONE = '01551096234'
const TEL = 'tel:+201551096234'
const WHATSAPP = 'https://wa.me/201551096234'
const EMAIL = 'watchizer303@gmail.com'
const MAILTO = `mailto:${EMAIL}`
// The header/footer links (developer, 2026-10-01: those, not the structured-data ones).
const FACEBOOK = 'https://www.facebook.com/profile.php?id=100076267296916'
const INSTAGRAM = 'https://www.instagram.com/watchizer_eg/'

export const TRUST_PAGES = {
  about: {
    path: '/about-us',
    en: {
      title: 'About Watchizer',
      metaTitle: 'About us | Watchizer',
      description: 'Watchizer is an online shop for watches and accessories in Egypt, delivering to 27 governorates.',
      lead: 'Watchizer is an online shop for watches and accessories in Egypt. We sell watches for men and women, along with bags, wallets, belts, jewellery and a small range of electronics, and we deliver to 27 governorates.',
      store: 'Shop at watchizereg.com in English or Arabic, or visit our store at Arkadia Mall, Corniche El Nil, Maspero extension, Cairo. It is open every day, 10:00–22:00.',
      service: "We confirm every order with you before it ships. You can pay in cash when it arrives, or online by card. If anything isn't right, our customer service team is on [WhatsApp](" + WHATSAPP + ') every day.',
    },
    ar: {
      title: 'من نحن',
      metaTitle: 'من نحن | Watchizer',
      description: 'واتشايزر متجر إلكتروني للساعات والإكسسوارات في مصر، ونوصّل إلى ٢٧ محافظة.',
      lead: 'واتشايزر متجر إلكتروني للساعات والإكسسوارات في مصر. نبيع ساعات رجالية ونسائية، إلى جانب الحقائب والمحافظ والأحزمة والمجوهرات ومجموعة صغيرة من الإلكترونيات، ونوصّل إلى ٢٧ محافظة.',
      store: 'تسوّق على watchizereg.com بالعربية أو الإنجليزية، أو زر متجرنا في أركاديا مول، كورنيش النيل، امتداد ماسبيرو، القاهرة. المتجر مفتوح يومياً من ١٠ صباحاً حتى ١٠ مساءً.',
      service: 'نؤكد كل طلب معك قبل شحنه. يمكنك الدفع نقداً عند الاستلام، أو بالبطاقة أونلاين. وإن كان هناك أي خطأ، فريق خدمة العملاء متاح على [واتساب](' + WHATSAPP + ') يومياً.',
    },
  },

  // The approved list: "Customer service (phone and WhatsApp): 01551096234. Every day, 10:00–22:00." /
  // "Email: …" / "Store: …. Every day, 10:00–22:00." / "Facebook: Watchizer · Instagram: @watchizer_eg".
  // As cards, the customer-service line heads the group and its number becomes a phone card and a
  // WhatsApp card (each labelled with the approved word for it).
  contact: {
    path: '/contact-us',
    en: {
      title: 'Contact us',
      metaTitle: 'Contact us | Watchizer',
      description: `Watchizer customer service: phone and WhatsApp ${PHONE}, every day 10:00–22:00. Email ${EMAIL}.`,
      group: 'Customer service (phone and WhatsApp)',
      hours: 'Every day, 10:00–22:00.',
      cards: [
        { icon: 'phone', label: 'Phone', value: PHONE, href: TEL, hours: true, ltr: true },
        { icon: 'whatsapp', label: 'WhatsApp', value: PHONE, href: WHATSAPP, hours: true, ltr: true },
        { icon: 'mail', label: 'Email', value: EMAIL, href: MAILTO, ltr: true },
        { icon: 'facebook', label: 'Facebook', value: 'Watchizer', href: FACEBOOK, ltr: true },
        { icon: 'instagram', label: 'Instagram', value: '@watchizer_eg', href: INSTAGRAM, ltr: true },
      ],
      store: { label: 'Store', address: 'Arkadia Mall, Corniche El Nil, Maspero extension, Cairo.' },
      note: "If your question is about an order, have your order number ready. You'll find it in your confirmation email and under [*My Orders*](/account?tab=orders).",
    },
    ar: {
      title: 'تواصل معنا',
      metaTitle: 'تواصل معنا | Watchizer',
      description: `خدمة عملاء واتشايزر: هاتف وواتساب ${PHONE}، يومياً من ١٠ صباحاً حتى ١٠ مساءً. البريد ${EMAIL}.`,
      group: 'خدمة العملاء (هاتف وواتساب)',
      hours: 'يومياً من ١٠ صباحاً حتى ١٠ مساءً.',
      cards: [
        { icon: 'phone', label: 'هاتف', value: PHONE, href: TEL, hours: true, ltr: true },
        { icon: 'whatsapp', label: 'واتساب', value: PHONE, href: WHATSAPP, hours: true, ltr: true },
        { icon: 'mail', label: 'البريد الإلكتروني', value: EMAIL, href: MAILTO, ltr: true },
        { icon: 'facebook', label: 'فيسبوك', value: 'Watchizer', href: FACEBOOK, ltr: true },
        { icon: 'instagram', label: 'إنستجرام', value: '@watchizer_eg', href: INSTAGRAM, ltr: true },
      ],
      store: { label: 'المتجر', address: 'أركاديا مول، كورنيش النيل، امتداد ماسبيرو، القاهرة.' },
      note: 'إن كان سؤالك عن طلب، جهّز رقم الطلب. تجده في رسالة التأكيد وفي [«طلباتي»](/account?tab=orders).',
    },
  },

  // Policies as numbered sections. `intro` (optional) comes before section 1. A section's `title` is the
  // bold lead of its approved paragraph; `blocks` are { p }, { ul: [item] }.
  privacy: {
    path: '/privacy-policy',
    updated: true,
    en: {
      title: 'Privacy policy',
      metaTitle: 'Privacy policy | Watchizer',
      description: 'What Watchizer collects, why, who it is shared with, and your choices.',
      sections: [
        {
          title: 'What we collect, and why',
          blocks: [
            {
              ul: [
                '**Your account.** We keep your name, email, phone number and password; the password is stored encrypted. We use them to sign you in and show your orders. You can also sign in with Google.',
                '**Your orders.** We keep your delivery address, phone number and the items you buy. We use them to deliver and to handle exchanges and returns. We keep order records for as long as we need them for that and for our accounts.',
                '**Card payments.** Our payment provider, Paymob, handles them. We never see or store your card number.',
                "**\"Email me when it's back\".** We keep the address you leave and use it only for that product. The email has a link to stop it. We delete the request 30 days after we email you, or after 180 days if the product doesn't come back.",
                "**Emails about new products.** If you have an account and haven't visited for a while, we may email you a few new products, at most once a week. Every email has an unsubscribe link, and unsubscribing is permanent.",
              ],
            },
          ],
        },
        {
          title: 'Cookies and similar storage',
          blocks: [{ p: "We store your language and your cart so the site remembers them. We use the Meta (Facebook) pixel and the TikTok pixel to measure our adverts. When you place an order, our server also sends Meta the order's value and items, for the same purpose." }],
        },
        {
          title: 'Who we share it with',
          blocks: [
            { p: 'Only what each one needs:' },
            { ul: ['our delivery partner: your name, address and phone number;', 'Paymob: card payments;', 'Meta and TikTok: advert measurement, as above;', 'our email provider.'] },
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
      metaTitle: 'سياسة الخصوصية | Watchizer',
      description: 'ما يجمعه واتشايزر من بيانات ولماذا، ومع من نشاركها، واختياراتك.',
      sections: [
        {
          title: 'ما نجمعه ولماذا',
          blocks: [
            {
              ul: [
                '**حسابك.** نحفظ اسمك وبريدك الإلكتروني ورقم هاتفك وكلمة المرور، وكلمة المرور محفوظة مشفّرة. نستخدمها لتسجيل دخولك وعرض طلباتك. يمكنك أيضاً تسجيل الدخول بحساب جوجل.',
                '**طلباتك.** نحفظ عنوان التوصيل ورقم الهاتف والمنتجات التي تشتريها. نستخدمها للتوصيل وللاستبدال والإرجاع. نحتفظ بسجلات الطلبات طالما احتجنا إليها لذلك ولحساباتنا.',
                '**الدفع بالبطاقة.** يتولاه مزوّد الدفع Paymob. لا نرى رقم بطاقتك ولا نحفظه.',
                '**«أبلغني عند التوفر».** نحفظ البريد الذي تتركه ونستخدمه لهذا المنتج فقط. في الرسالة رابط لإيقافها. نحذف الطلب بعد ٣٠ يوماً من مراسلتك، أو بعد ١٨٠ يوماً إن لم يعد المنتج متوفراً.',
                '**رسائل المنتجات الجديدة.** إن كان لديك حساب ولم تزر الموقع منذ فترة، قد نرسل إليك بعض المنتجات الجديدة، مرة في الأسبوع على الأكثر. في كل رسالة رابط لإلغاء الاشتراك، والإلغاء نهائي.',
              ],
            },
          ],
        },
        {
          title: 'ملفات تعريف الارتباط والتخزين المشابه',
          blocks: [{ p: 'نحفظ لغتك وسلتك ليتذكرهما الموقع. نستخدم بكسل ميتا (فيسبوك) وبكسل تيك توك لقياس إعلاناتنا. وعند إتمام الطلب يرسل خادمنا أيضاً إلى ميتا قيمة الطلب ومنتجاته، للغرض نفسه.' }],
        },
        {
          title: 'مع من نشاركها',
          blocks: [
            { p: 'فقط ما يحتاجه كل طرف:' },
            { ul: ['شريك التوصيل: الاسم والعنوان ورقم الهاتف؛', 'Paymob: الدفع بالبطاقة؛', 'ميتا وتيك توك: قياس الإعلانات كما سبق؛', 'مزوّد البريد الإلكتروني.'] },
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

  terms: {
    path: '/terms-and-conditions',
    updated: true,
    en: {
      title: 'Terms and conditions',
      metaTitle: 'Terms and conditions | Watchizer',
      description: 'Prices, orders, payment, delivery, exchanges and returns, and warranty at Watchizer.',
      intro: 'These terms apply to every order placed on watchizereg.com. "We" and "Watchizer" mean the shop; "you" means the customer.',
      sections: [
        { title: 'Prices', blocks: [{ p: 'Prices are in Egyptian pounds. A price can change until you place your order; the price shown at checkout is the price you pay.' }] },
        { title: 'Orders', blocks: [{ p: 'Your order is accepted when we confirm it with you. If an item turns out to be unavailable, we tell you and refund anything you have already paid.' }] },
        {
          title: 'Payment',
          blocks: [
            { p: 'You can pay:' },
            { ul: ['cash on delivery;', 'by card online through Paymob;', 'with the other Paymob methods shown at checkout.'] },
            { p: 'Some methods apply only above or below a certain order amount; checkout shows what is available for your order.' },
          ],
        },
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
        { title: 'Warranty', blocks: [{ p: 'When a product page states a warranty, it applies as stated there. Contact customer service to use it.' }] },
        { title: 'Your account', blocks: [{ p: 'Keep your password to yourself. You are responsible for orders placed from your account.' }] },
        { title: 'Changes', blocks: [{ p: 'We may update these terms. The version published when you place an order applies to that order.' }] },
        { title: 'Law', blocks: [{ p: 'These terms are governed by the laws of Egypt.' }] },
      ],
    },
    ar: {
      title: 'الشروط والأحكام',
      metaTitle: 'الشروط والأحكام | Watchizer',
      description: 'الأسعار والطلبات والدفع والتوصيل والاستبدال والإرجاع والضمان في واتشايزر.',
      intro: 'تنطبق هذه الشروط على كل طلب يتم عبر watchizereg.com. «نحن» و«واتشايزر» تعني المتجر، و«أنت» تعني العميل.',
      sections: [
        { title: 'الأسعار', blocks: [{ p: 'الأسعار بالجنيه المصري. قد يتغير السعر حتى تتم طلبك؛ السعر المعروض عند إتمام الطلب هو ما تدفعه.' }] },
        { title: 'الطلبات', blocks: [{ p: 'يُقبل طلبك عندما نؤكده معك. إن تبيّن أن منتجاً غير متوفر، نبلغك ونرد لك أي مبلغ دفعته.' }] },
        {
          title: 'الدفع',
          blocks: [
            { p: 'يمكنك الدفع:' },
            { ul: ['نقداً عند الاستلام؛', 'بالبطاقة أونلاين عبر Paymob؛', 'بوسائل Paymob الأخرى المعروضة عند إتمام الطلب.'] },
            { p: 'بعض الوسائل متاحة فقط فوق أو تحت قيمة معينة للطلب، وتعرض صفحة إتمام الطلب المتاح لطلبك.' },
          ],
        },
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
        { title: 'الضمان', blocks: [{ p: 'عندما تذكر صفحة المنتج ضماناً، فهو يسري كما هو مذكور فيها. تواصل مع خدمة العملاء للاستفادة منه.' }] },
        { title: 'حسابك', blocks: [{ p: 'احتفظ بكلمة المرور لنفسك. أنت مسؤول عن الطلبات التي تتم من حسابك.' }] },
        { title: 'التعديلات', blocks: [{ p: 'قد نحدّث هذه الشروط. تنطبق على كل طلب النسخة المنشورة وقت إتمامه.' }] },
        { title: 'القانون', blocks: [{ p: 'تخضع هذه الشروط للقوانين المصرية.' }] },
      ],
    },
  },
}
