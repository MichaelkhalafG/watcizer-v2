// node --test resources/js/lib/offerBuilder.test.ts  (Node strips the types; no build step)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { TEMPLATES, modelOf, sentence, warnings, type FormLike, type NameOf } from './offerBuilder.ts';

const NAMES: Record<string, { en: string; ar: string }> = {
    'category:22': { en: 'Wallets', ar: 'محافظ' }, // i18n-exempt: TEST DATA: the expected Arabic sentence
    'category:1': { en: 'Watches', ar: 'ساعات' }, // i18n-exempt: TEST DATA: the expected Arabic sentence
    'brand:7': { en: 'Tissot', ar: 'تيسو' }, // i18n-exempt: TEST DATA: the expected Arabic sentence
};
const nameOf: NameOf = (dimension, id) => NAMES[`${dimension}:${id}`] ?? null;
const ctx = { categoryBySlug: (slug: string) => ({ wallets: 22, watches: 1 })[slug] ?? null };

const blank: FormLike = {
    rewards: [], conditions: [], scopes: [], apply_per: 'order', max_discount: '', max_units: '', qualify_min_quantity: '',
    min_order_amount: '', usage_limit: '', ends_at: '', code: '', for_wheel: false,
};
const fromTemplate = (key: string, extra: Partial<FormLike> = {}): FormLike => ({ ...blank, ...TEMPLATES.find((t) => t.key === key)!.patch(ctx), ...extra }) as FormLike;

test('says "30% off a wallet with any watch" plainly in both languages, from its template', () => {
    const m = modelOf(fromTemplate('wallet_with_watch', { for_wheel: true }), nameOf);
    assert.equal(m.items, 'per_qualifier');
    assert.equal(sentence(m, 'en'), '30% off one item from Wallets for each item from Watches in the cart, the most expensive first, up to 1,500 EGP. Won on the wheel.');
    assert.equal(sentence(m, 'ar'), 'خصم 30% على منتج واحد من محافظ مع كل منتج من ساعات في السلة، الأغلى أولًا، حتى 1,500 جنيه. جائزة في عجلة الحظ.'); // i18n-exempt: TEST DATA: the expected Arabic sentence
    assert.deepEqual(warnings(m, 'en'), []);
});

test('the wallet template targets the shop\'s Wallets and qualifies on its Watches, one per watch, capped', () => {
    const p = TEMPLATES.find((t) => t.key === 'wallet_with_watch')!.patch(ctx);
    assert.deepEqual(p.scopes, [
        { role: 'target', dimension: 'category', value_id: 22, value: null },
        { role: 'qualify', dimension: 'category', value_id: 1, value: null },
    ]);
    assert.equal(p.apply_per, 'paired');
    assert.equal(p.max_discount, '1500');
    assert.equal(p.qualify_min_quantity, '1');
    assert.equal(p.label_ar, 'خصم 30% على محفظة مع أي ساعة'); // i18n-exempt: TEST DATA: the expected Arabic sentence
});

test('says buy 2 get the 3rd free, a brand percentage, an amount above a total, free delivery and a first order', () => {
    assert.equal(sentence(modelOf(fromTemplate('buy2_get3rd', { code: 'THIRD', ends_at: '2026-12-31T23:59' }), nameOf), 'en'), 'Buy 3 from Watches: the cheapest one free, until 31 December. With the code THIRD.');
    assert.equal(sentence(modelOf({ ...fromTemplate('percent_brand'), scopes: [{ role: 'target', dimension: 'brand', value_id: 7, value: null }], max_discount: '800', ends_at: '2026-12-31T23:59' }, nameOf), 'en'), '10% off Tissot, up to 800 EGP, until 31 December. Applied automatically.');
    assert.equal(sentence(modelOf(fromTemplate('amount_over_total', { ends_at: '2026-12-31' }), nameOf), 'ar'), 'خصم 300 جنيه على السلة كلها، للطلبات من 5,000 جنيه فأكثر، حتى 31 ديسمبر. يُطبَّق تلقائيًا.'); // i18n-exempt: TEST DATA: the expected Arabic sentence
    assert.equal(sentence(modelOf(fromTemplate('free_shipping_over', { ends_at: '2026-12-31' }), nameOf), 'en'), 'Free delivery, on orders of 3,000 EGP or more, until 31 December. Applied automatically.');
    assert.equal(sentence(modelOf(fromTemplate('first_order', { code: 'HELLO', ends_at: '2026-12-31' }), nameOf), 'en'), '10% off the whole cart, on a first order only, up to 1,000 EGP, until 31 December. With the code HELLO.');
});

test('a fixed maximum of items, a condition item and the number of uses are said too', () => {
    const data: FormLike = {
        ...blank, rewards: [{ type: 'percent_discount', amount: '20', product_id: null, quantity: 1 }],
        scopes: [{ role: 'target', dimension: 'category', value_id: 22, value: null }, { role: 'qualify', dimension: 'brand', value_id: 7, value: null }],
        apply_per: 'item', max_units: '2', max_discount: '1000', usage_limit: '50', ends_at: '2026-12-31',
    };
    assert.equal(sentence(modelOf(data, nameOf), 'en'), '20% off Wallets, with an item from Tissot in the cart, on up to 2 items, up to 1,000 EGP, for the first 50 uses, until 31 December. Applied automatically.');
});

test('warns about a discount with no cap or no end date, and pairing without a qualifying item', () => {
    const m = modelOf({ ...blank, rewards: [{ type: 'percent_discount', amount: '20', product_id: null, quantity: 1 }], apply_per: 'paired' }, nameOf);
    const w = warnings(m, 'en');
    assert.equal(w.length, 3);
    assert.match(w[0], /no cap/);
    assert.match(w[1], /no end date/);
    assert.match(w[2], /qualifying item/);
    assert.equal(warnings(m, 'ar').length, 3);
});

test('every template has a label and a hint in both languages, and none uses an internal word', () => {
    for (const t of TEMPLATES) {
        for (const lang of ['en', 'ar'] as const) {
            assert.ok(t.label[lang].length > 3 && t.hint[lang].length > 10, t.key);
            assert.doesNotMatch(t.label[lang] + t.hint[lang], /priority|scope|issuer|apply_per|qualify_min|max_units/i);
        }
    }
});
