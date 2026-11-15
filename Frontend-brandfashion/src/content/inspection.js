// INSPECTION BEFORE PAYMENT (Michael and the client, 2026-11-14) — the SAME words everywhere they appear: the trust
// strips, the product page's promises, the checkout's cash-on-delivery option, the FAQ, the terms, the About page,
// /llms.txt and (Phase 4) the articles' facts. A TWIN: both shops say exactly this.
//
//   INSPECT_SHORT  the strips and the product page's promises
//   INSPECT_LONG   the checkout's cash-on-delivery option
//   INSPECT_FULL   the long sentence with the client's condition: the FAQ, the terms, the About page, /llms.txt
export const INSPECT_SHORT = {
  en: 'Inspect before you pay (cash on delivery)',
  ar: 'المعاينة قبل الدفع (الدفع عند الاستلام)',
}

export const INSPECT_LONG = {
  en: 'Inspect before you pay: with cash on delivery, you can open your order and check it in front of the courier before paying.',
  ar: 'المعاينة قبل الدفع: عند الدفع نقدًا عند الاستلام، يمكنك فتح طلبك ومعاينته أمام المندوب قبل أن تدفع.',
}

// the client's condition (2026-11-14): refusing the order after inspecting it costs no delivery fee
export const INSPECT_NO_FEE = {
  en: 'If you refuse the order after inspecting it, you pay no delivery fee.',
  ar: 'وإذا رفضت الطلب بعد معاينته، فلن تدفع رسوم التوصيل.',
}

export const INSPECT_FULL = {
  en: `${INSPECT_LONG.en} ${INSPECT_NO_FEE.en}`,
  ar: `${INSPECT_LONG.ar} ${INSPECT_NO_FEE.ar}`,
}
