// /faq and /ar/faq — Brand Fashion's questions and answers (SEO item 5, 2026-10-30). The answers are the client's
// business facts of 2026-10-29: delivery to every governorate within 24 hours, free over EGP 10,000;
// returns and exchange within 14 days, unopened with the seals intact, return shipping
// paid by the customer, refund after inspection by the same payment method; warranty up to 3 years depending on the
// watch; battery and bracelet sizing free within the warranty. The number and the stores are the contact page's.
// Never: a founding year, international shipping, a money-back promise, inspection before payment.
// PAYMENT is Michael's wording of 2026-10-30: "all payment methods and instalment programmes are available" (the
// recommendation D-S5 — cash on delivery, what the checkout offered when measured — was reverted by his decision).
//
// This file is Brand Fashion's own (Watchizer has its own facts and number). Answers use the trust pages' inline
// marks — **bold** and [label](href) — and the page strips them for the FAQPage JSON-LD.

const PHONE = '01283888490'
const WHATSAPP = 'https://wa.me/201283888490'

export const FAQ = {
  path: '/faq',
  en: {
    title: 'Frequently asked questions',
    metaTitle: 'FAQ: delivery, payment, returns and warranty | Brand Fashion',
    description: 'Answers about ordering from Brand Fashion: delivery to every governorate within 24 hours, payment and instalments, 14-day returns and exchanges, and the warranty.',
    intro: 'The questions we are asked most, about ordering, delivery, payment, returns and the warranty. If yours is not here, [contact us](/contact-us).',
    groups: [
      {
        title: 'Orders and delivery',
        items: [
          { q: 'How long does delivery take?', a: 'We deliver to **every governorate in Egypt within 24 hours**.' },
          { q: 'How much does delivery cost?', a: 'It depends on your governorate, and the cart shows it before you pay, as soon as you choose where to deliver. **Orders over EGP 10,000 ship free**, and the cart tells you how far you are from that.' },
          { q: 'How do I know my order went through?', a: 'You receive a confirmation e-mail with your order number. Keep it: it is what we ask for if you contact us about the order.' },
        ],
      },
      {
        title: 'Payment',
        items: [
          { q: 'How can I pay?', a: '**All payment methods and instalment programmes are available.** The total on the checkout page is the total you pay.' },
          { q: 'How do I use a discount code?', a: 'Type it in the cart. The cart shows the discount and the new total straight away, and if a code cannot be used on your cart it says why — for example, that it has expired or does not include the items you chose.' },
        ],
      },
      {
        title: 'Returns and exchanges',
        items: [
          { q: 'Can I return or exchange an item?', a: 'Yes, **within 14 days**. The item must be in its original, unopened state, with its seals intact. Perfumes are exchanged only sealed.' },
          { q: 'Who pays for the return, and how is the refund made?', a: 'Return shipping is paid by the customer. Once the item has reached us and been inspected, the refund is made by the same payment method you used.' },
        ],
      },
      {
        title: 'Originality and warranty',
        items: [
          { q: 'Are your watches original?', a: 'Yes. Every watch comes with its evidence: the serial number and its stamps on the watch, the brand’s own box and catalogue, a warranty card and a sales invoice.' },
          { q: 'How long is the warranty?', a: '**Up to 3 years, depending on the watch** — the length for each watch is on its product page. It covers the movement against manufacturing defects.' },
          { q: 'Do you change batteries and size bracelets?', a: 'Within the warranty, **battery replacement and bracelet sizing are free** — bring the watch to either store with its warranty card and invoice. Other work, such as a repair outside the warranty, is done for a fee. A watch you did not buy from us can still be looked at, for a fee that depends on the fault.' },
        ],
      },
      {
        title: 'Stores and contact',
        items: [
          { q: 'Do you have stores?', a: 'Yes, two: at **Arcadia Mall**, Corniche El Nil, Maspero extension, next to the Fairmont, 2nd floor, Cairo, and at **90 El-Thawra Street, Heliopolis**. Both are open every day, 10:00–24:00.' },
          { q: 'How can I reach customer service?', a: 'By phone or [WhatsApp](' + WHATSAPP + ') on **' + PHONE + '**, or through the [contact page](/contact-us).' },
        ],
      },
    ],
  },
  ar: {
    title: 'الأسئلة الشائعة',
    metaTitle: 'الأسئلة الشائعة: التوصيل والدفع والاسترجاع والضمان | براند فاشون',
    description: 'إجابات عن الطلب من براند فاشون: التوصيل لكل المحافظات خلال ٢٤ ساعة، والدفع والتقسيط، والاسترجاع والاستبدال خلال ١٤ يوماً، والضمان.',
    intro: 'أكثر الأسئلة التي تصلنا عن الطلب والتوصيل والدفع والاسترجاع والضمان. إن لم تجد سؤالك هنا، [تواصل معنا](/contact-us).',
    groups: [
      {
        title: 'الطلبات والتوصيل',
        items: [
          { q: 'كم يستغرق التوصيل؟', a: 'نوصّل إلى **كل محافظات مصر خلال ٢٤ ساعة**.' },
          { q: 'كم تكلفة التوصيل؟', a: 'تختلف حسب محافظتك، وتظهر في السلة قبل الدفع بمجرد اختيار مكان التوصيل. **الطلبات فوق ١٠٬٠٠٠ جنيه شحنها مجاني**، والسلة تخبرك كم يتبقى لتصل إليه.' },
          { q: 'كيف أعرف أن طلبي تم؟', a: 'يصلك بريد تأكيد برقم طلبك. احتفظ به: هو ما نسألك عنه إن تواصلت معنا بخصوص الطلب.' },
        ],
      },
      {
        title: 'الدفع',
        items: [
          { q: 'كيف أدفع؟', a: '**كل طرق الدفع وبرامج التقسيط متاحة.** والإجمالي في صفحة الدفع هو ما تدفعه.' },
          { q: 'كيف أستخدم كود الخصم؟', a: 'اكتبه في السلة. تعرض السلة الخصم والإجمالي الجديد فوراً، وإن لم يكن الكود صالحاً لسلتك تخبرك بالسبب — مثلاً أنه انتهى أو لا يشمل المنتجات التي اخترتها.' },
        ],
      },
      {
        title: 'الاسترجاع والاستبدال',
        items: [
          { q: 'هل يمكنني استرجاع منتج أو استبداله؟', a: 'نعم، **خلال ١٤ يوماً**. ويجب أن يكون المنتج بحالته الأصلية دون فتح، وأختامه سليمة. وتُستبدل العطور مغلقة فقط.' },
          { q: 'من يدفع شحن الإرجاع، وكيف يُرد المبلغ؟', a: 'شحن الإرجاع على العميل. وبعد وصول المنتج إلينا وفحصه، يُرد المبلغ بوسيلة الدفع نفسها التي استخدمتها.' },
        ],
      },
      {
        title: 'الأصالة والضمان',
        items: [
          { q: 'هل ساعاتكم أصلية؟', a: 'نعم. كل ساعة تأتي بما يثبت أصالتها: الرقم التسلسلي وأختامه على الساعة، وعلبة الماركة وكتالوجها، وبطاقة الضمان، وفاتورة البيع.' },
          { q: 'كم مدة الضمان؟', a: '**حتى ٣ سنوات حسب الساعة** — ومدة كل ساعة في صفحة المنتج. ويغطي الماكينة ضد عيوب التصنيع.' },
          { q: 'هل تغيّرون البطاريات وتضبطون مقاس الأساور؟', a: 'خلال فترة الضمان، **تغيير البطارية وضبط مقاس السوار مجاناً** — أحضر الساعة إلى أي من متجرينا مع بطاقة الضمان والفاتورة. أما الأعمال الأخرى، مثل الإصلاح خارج الضمان، فتتم بمقابل. ويمكننا فحص ساعة لم تشترها منا، بمقابل يتوقف على العطل.' },
        ],
      },
      {
        title: 'المتاجر والتواصل',
        items: [
          { q: 'هل لديكم متاجر؟', a: 'نعم، فرعان: في **أركاديا مول**، كورنيش النيل، امتداد ماسبيرو، بجوار فندق فيرمونت، الدور الثاني، القاهرة، وفي **٩٠ شارع الثورة، مصر الجديدة**. وكلاهما مفتوح يومياً من ١٠ صباحاً حتى منتصف الليل.' },
          { q: 'كيف أتواصل مع خدمة العملاء؟', a: 'بالهاتف أو [واتساب](' + WHATSAPP + ') على **' + PHONE + '**، أو من خلال [صفحة التواصل](/contact-us).' },
        ],
      },
    ],
  },
}
