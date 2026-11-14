'use client'
import { FiMapPin, FiTruck } from 'react-icons/fi'
import { inLang } from '../../lib/pickup'

/*
 * "Delivery or pickup" on the checkout (click & collect, 2026-11-14): the two ways to get the order, and — once
 * "Pick up in store" is chosen — the shop's stores to choose from. Drawn with the checkout's own rows (the payment
 * options' radio row, dot, title, note and icon slot), so each shop's Checkout.css dresses it in its own skin: a TWIN
 * (Frontend-next and Frontend-brandfashion, identical). Nothing here decides anything: the stores, the windows and the
 * hold are core's answer (usePickup), and the order is checked again by core when it is placed.
 */
export default function PickupChoice({ pickup, isPickup, onChoose, storeId, onStore, ready, deliveryNote, isRTL, error }) {
  const t = (en, ar) => (isRTL ? ar : en)
  const readyText = ready ? inLang(ready.sentence, isRTL) : ''

  return (
    <>
      <div className="wz-checkout-pay wz-pickup-modes" role="radiogroup" aria-label={t('Delivery or pickup', 'التوصيل أو الاستلام')}>
        <label className={`wz-checkout-payopt${isPickup ? '' : ' is-active'}`}>
          <input
            type="radio"
            name="fulfilment"
            value="delivery"
            className="wz-pickup-radio"
            checked={!isPickup}
            onChange={() => onChoose('delivery')}
          />
          <span className="wz-checkout-dot" aria-hidden="true" />
          <div className="wz-checkout-payopt-main">
            <div className="wz-checkout-payopt-title">
              {t('Home delivery', 'توصيل للعنوان')}
              <span className="wz-checkout-cards" aria-hidden="true">
                <FiTruck />
              </span>
            </div>
            <div className="wz-checkout-payopt-note">{deliveryNote}</div>
          </div>
        </label>

        <label className={`wz-checkout-payopt${isPickup ? ' is-active' : ''}`}>
          <input
            type="radio"
            name="fulfilment"
            value="pickup"
            className="wz-pickup-radio"
            checked={isPickup}
            onChange={() => onChoose('pickup')}
          />
          <span className="wz-checkout-dot" aria-hidden="true" />
          <div className="wz-checkout-payopt-main">
            <div className="wz-checkout-payopt-title">
              {t('Pick up in store', 'استلام من الفرع')}
              <span className="wz-checkout-cards" aria-hidden="true">
                <FiMapPin />
              </span>
            </div>
            <div className="wz-checkout-payopt-note">
              {readyText ? `${readyText} · ` : ''}
              {t('Free', 'مجانًا')}
            </div>
          </div>
        </label>
      </div>

      {isPickup ? (
        <div className="wz-pickup-stores">
          <p className="wz-checkout-label" id="wz-pickup-stores-label">
            {t('Choose a store', 'اختر الفرع')}
            <span className="req">*</span>
          </p>
          <div className="wz-checkout-pay" role="radiogroup" aria-labelledby="wz-pickup-stores-label">
            {pickup.stores.map((s) => {
              const active = storeId === s.id
              const hours = inLang(s.hours, isRTL)
              return (
                <label key={s.id} className={`wz-checkout-payopt${active ? ' is-active' : ''}`}>
                  <input
                    type="radio"
                    name="pickup-store"
                    value={s.id}
                    className="wz-pickup-radio"
                    checked={active}
                    onChange={() => onStore(s.id)}
                  />
                  <span className="wz-checkout-dot" aria-hidden="true" />
                  <div className="wz-checkout-payopt-main">
                    <div className="wz-checkout-payopt-title">{inLang(s.name, isRTL)}</div>
                    <div className="wz-checkout-payopt-note">{inLang(s.address, isRTL)}</div>
                    {hours ? <div className="wz-checkout-payopt-note">{hours}</div> : null}
                  </div>
                  {s.map_url ? (
                    <a
                      className="wz-pickup-map"
                      href={s.map_url}
                      target="_blank"
                      rel="noopener noreferrer"
                      onClick={(e) => e.stopPropagation()}
                    >
                      {t('Map', 'الخريطة')}
                    </a>
                  ) : null}
                </label>
              )
            })}
          </div>
          {error ? <span className="wz-checkout-err">{error}</span> : null}
        </div>
      ) : null}
    </>
  )
}
