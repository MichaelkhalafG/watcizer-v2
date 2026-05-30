import { useContext, useState, useEffect, useCallback } from 'react'
import {
  Button,
  TextField,
  MenuItem,
  Select,
  FormControl,
  InputLabel,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Alert,
  Snackbar,
  Radio,
  RadioGroup,
  FormControlLabel,
  FormLabel,
} from '@mui/material'
import { MyContext } from '../../Context/Context'
import http from '../../Context/api'
import useCart, { getItemKey } from '../../Hooks/useCart'
import TrustSignals from '../../Components/Merchandising/TrustSignals'

function Checkout() {
  const {
    language,
    user_id,
    windowWidth,
    products,
    offers,
    shippingPrices,
    shippingname,
    shipping,
    setShipping,
    setShippingName,
    shippingid,
    setShippingid,
  } = useContext(MyContext)
  const [formData, setFormData] = useState({
    address: '',
    city: shippingname,
    paymentMethod: 'cash',
  })
  const [addresses, setAddresses] = useState([])
  const [loadingAddresses, setLoadingAddresses] = useState(true)
  const [openAddAddress, setOpenAddAddress] = useState(false)
  const [streetName, setStreetName] = useState('')
  const [buildingNumber, setBuildingNumber] = useState('')
  const [floorNumber, setFloorNumber] = useState('')
  const [apartmentNumber, setApartmentNumber] = useState('')
  const [phoneNumber, setPhoneNumber] = useState('')
  const [alertMessage, setAlertMessage] = useState('')
  const [alertType, setAlertType] = useState('info')
  const [openAlert, setOpenAlert] = useState(false)
  const { cart, removeItem } = useCart()
  const showAlert = (message, type) => {
    setAlertMessage(message)
    setAlertType(type)
    setOpenAlert(true)
  }

  const fetchAddresses = useCallback(() => {
    http
      .get('me/addresses')
      .then((response) => {
        const addresses = response.data || []
        setAddresses(addresses)
        if (addresses.length > 0) {
          setFormData((prev) => ({ ...prev, address: addresses[0].id }))
        }
      })
      // .catch(error => console.error("Error fetching addresses:", error))
      .finally(() => setLoadingAddresses(false))
  }, [user_id])

  useEffect(() => {
    fetchAddresses()
  }, [fetchAddresses])

  const handleChangeShipping = (event) => {
    const selectedId = event.target.value
    setShippingid(selectedId)

    const selectedShipping = shippingPrices.find((city) => city.id === selectedId)
    if (selectedShipping) {
      setShipping(selectedShipping.Price.toString())
      setShippingName(
        language === 'ar' ? selectedShipping.GovernorateAr : selectedShipping.GovernorateEn,
      )
    }
  }

  const handleChangeAddress = (event) => {
    setFormData((prev) => ({
      ...prev,
      address: event.target.value,
    }))
  }

  const handlePaymentMethodChange = (event) => {
    setFormData((prev) => ({
      ...prev,
      paymentMethod: event.target.value,
    }))
  }

  const handleAddAddress = () => {
    if (!shippingid) {
      showAlert(
        language === 'ar'
          ? 'الرجاء اختيار مدينة الشحن أولاً'
          : 'Please select a shipping city first',
        'warning',
      )
      return
    }
    if (!streetName.trim() || !buildingNumber.trim()) {
      showAlert(
        language === 'ar'
          ? 'يرجى إدخال اسم الشارع ورقم المبنى.'
          : 'Please enter the street name and building number.',
        'warning',
      )
      return
    }
    if (!phoneNumber.trim()) {
      showAlert(
        language === 'ar' ? 'يرجى إدخال رقم الهاتف.' : 'Please enter a phone number.',
        'warning',
      )
      return
    }
    let fullAddress = `${streetName}, Building ${buildingNumber}`
    if (floorNumber.trim()) fullAddress += `, Floor ${floorNumber}`
    if (apartmentNumber.trim()) fullAddress += `, Apartment ${apartmentNumber}`
    const payload = {
      user_id,
      shipping_city_id: shippingid,
      address_line: fullAddress,
      phone_number_one: phoneNumber,
    }
    http.post('/add_address', payload).then((response) => {
      if (response.data.success) {
        showAlert(
          language === 'ar' ? 'تم إضافة العنوان بنجاح!' : 'Address added successfully!',
          'success',
        )
        fetchAddresses()
        setOpenAddAddress(false)
        setStreetName('')
        setBuildingNumber('')
        setFloorNumber('')
        setApartmentNumber('')
        setPhoneNumber('')
      } else {
        showAlert(language === 'ar' ? 'فشل إضافة العنوان.' : 'Failed to add address.', 'error')
      }
    })
    // .catch(error => console.error("Error adding address:", error));
  }

  const getLabel = (key) => {
    const labels = {
      shippingCity: language === 'ar' ? 'مدينة الشحن' : 'Shipping City',
      address: language === 'ar' ? 'العنوان' : 'Address',
      phoneNumber: language === 'ar' ? 'رقم الهاتف' : 'Phone Number',
      addAddress: language === 'ar' ? 'إضافة عنوان' : 'Add Address',
      cancel: language === 'ar' ? 'إلغاء' : 'Cancel',
      add: language === 'ar' ? 'إضافة' : 'Add',
      paymentMethod: language === 'ar' ? 'طريقة الدفع' : 'Payment Method',
      cash: language === 'ar' ? 'نقداً' : 'Cash',
      card: language === 'ar' ? 'بطاقة' : 'Card',
    }
    return labels[key]
  }

  const handleDeleteCartItem = (item) => {
    removeItem(getItemKey(item))
    showAlert(language === 'ar' ? 'تم حذف المنتج من السلة' : 'Item deleted from cart', 'success')
  }

  const addOrder = () => {
    let totalPriceForOrder =
      cart.cart_item.reduce(
        (total, item) => total + (item.piece_price || 0) * (item.quantity || 0),
        0,
      ) + (isNaN(parseFloat(shipping)) ? 0 : parseFloat(shipping))
    if (isNaN(totalPriceForOrder) || totalPriceForOrder <= 0) {
      showAlert(language === 'ar' ? 'مجموع السعر غير صحيح.' : 'Total price is invalid.', 'warning')
      return
    }

    totalPriceForOrder = Math.floor(totalPriceForOrder)

    const selectedAddress = addresses.find((address) => address.id === formData.address)

    if (!selectedAddress) {
      showAlert(
        language === 'ar'
          ? 'الرجاء اختيار عنوان الشحن أولاً'
          : 'Please select a shipping address first',
        'warning',
      )
      return
    }

    const selectedPaymentMethod = formData.paymentMethod
    if (!selectedPaymentMethod) {
      showAlert(
        language === 'ar'
          ? 'الرجاء اختيار طريقة الدفع أولاً'
          : 'Please select a payment method first',
        'warning',
      )
      return
    }

    const orderNote = ''
    const items = cart.cart_item.map((i) => ({
      product_id: i.product_id,
      offer_id: i.offer_id ?? null,
      quantity: i.quantity,
      piece_price: i.piece_price,
      total_price: i.total_price,
      type_stock: i.type_stock,
      color_band: i.color_band ?? null,
      color_dial: i.color_dial ?? null,
    }))
    const requestData = {
      user_id: user_id,
      address_id: selectedAddress.id,
      total_price_for_order: totalPriceForOrder,
      payment_method: selectedPaymentMethod,
      note: orderNote,
      items,
    }

    http
      .post('/add_order', requestData)
      .then((response) => {
        if (response.data.success) {
          showAlert(
            language === 'ar' ? 'تم إرسال الطلب بنجاح!' : 'Order submitted successfully!',
            'success',
          )
          const redirectUrl = response.data.redirect_url || 'https://watchizereg.com/order-list'
          window.location.href = redirectUrl
          localStorage.clear()
          sessionStorage.removeItem('user_cart')
        } else {
          showAlert(language === 'ar' ? 'فشل في إرسال الطلب.' : 'Failed to submit order.', 'error')
        }
      })
      .catch((error) => {
        // console.error("Error submitting order:", error);
        if (error.response) {
          // console.error("Response error data:", error.response.data);
        }
        showAlert(language === 'ar' ? 'فشل في إرسال الطلب.' : 'Failed to submit order.', 'error')
      })
  }

  return (
    <div
      className="cart container mb-md-0 mb-5"
      dir={language === 'ar' ? 'rtl' : 'ltr'}
      style={{ textAlign: language === 'ar' ? 'right' : 'left' }}
    >
      <Snackbar
        open={openAlert}
        autoHideDuration={3000}
        onClose={() => setOpenAlert(false)}
        anchorOrigin={{
          vertical: windowWidth >= 768 ? 'bottom' : 'top',
          horizontal: windowWidth >= 768 ? 'right' : 'left',
        }}
      >
        <Alert severity={alertType} onClose={() => setOpenAlert(false)}>
          {alertMessage}
        </Alert>
      </Snackbar>
      <div className="row mb-md-0 mb-5 py-3">
        <div className="col-12 p-3">
          <h4 className="color-most-used fw-bold">
            {language === 'ar' ? 'تفاصيل الفواتير' : 'BILLING DETAILS'}
          </h4>
        </div>
        <div className="col-12 px-3">
          <TrustSignals variant="checkout" />
        </div>
        <div className="row m-0">
          <div className="checkout-container col-md-9 col-12">
            <FormControl fullWidth>
              <InputLabel> {language === 'ar' ? 'مدينة الشحن' : 'Shipping City'} </InputLabel>
              <Select value={shippingid || ''} onChange={handleChangeShipping} fullWidth>
                {shippingPrices.map((city) => (
                  <MenuItem key={city.id} value={city.id.toString()}>
                    {language === 'ar' ? city.GovernorateAr : city.GovernorateEn}
                  </MenuItem>
                ))}
              </Select>
            </FormControl>

            {loadingAddresses ? (
              <p>{language === 'ar' ? 'جاري تحميل العناوين...' : 'Loading addresses...'}</p>
            ) : addresses.length > 0 ? (
              <>
                <FormControl fullWidth style={{ marginTop: 20 }}>
                  <InputLabel>{language === 'ar' ? 'العنوان' : 'Address'}</InputLabel>
                  <Select value={formData.address} onChange={handleChangeAddress} fullWidth>
                    {addresses.map((address) => (
                      <MenuItem key={address.id} value={address.id}>
                        {address.address_line}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
                <Button
                  onClick={() => setOpenAddAddress(true)}
                  variant="contained"
                  color="primary"
                  style={{ marginTop: 20 }}
                >
                  {language === 'ar' ? 'إضافة عنوان' : 'Add Address'}
                </Button>
              </>
            ) : (
              <Button
                onClick={() => setOpenAddAddress(true)}
                variant="contained"
                color="primary"
                style={{ marginTop: 20 }}
              >
                {language === 'ar' ? 'إضافة عنوان' : 'Add Address'}
              </Button>
            )}
            <FormControl component="fieldset" style={{ marginTop: 20, width: '100%' }}>
              <FormLabel
                component="legend"
                style={{ fontWeight: 'bold', fontSize: '1.1rem', marginBottom: 8 }}
              >
                {getLabel('paymentMethod')}
              </FormLabel>
              <RadioGroup
                value={formData.paymentMethod}
                onChange={handlePaymentMethodChange}
                name="payment-method"
                sx={{
                  flexDirection: { xs: 'column', sm: 'row' },
                  gap: 2,
                  background: '#f9f9fb',
                  borderRadius: 2,
                  padding: 2,
                  border: '1px solid #ececec',
                }}
              >
                <FormControlLabel
                  value="cash"
                  control={
                    <Radio sx={{ color: '#262626', '&.Mui-checked': { color: '#262626' } }} />
                  }
                  label={
                    <span style={{ fontWeight: 600, fontSize: '1rem' }}>{getLabel('cash')}</span>
                  }
                  sx={{ marginBottom: { xs: 1, sm: 0 } }}
                />
                <FormControlLabel
                  value="card"
                  control={
                    <Radio sx={{ color: '#262626', '&.Mui-checked': { color: '#262626' } }} />
                  }
                  label={
                    <span style={{ fontWeight: 600, fontSize: '1rem' }}>{getLabel('card')}</span>
                  }
                />
              </RadioGroup>
            </FormControl>
            <Dialog open={openAddAddress} onClose={() => setOpenAddAddress(false)}>
              <DialogTitle>
                {language === 'ar' ? 'إضافة عنوان جديد' : 'Add New Address'}
              </DialogTitle>
              <DialogContent>
                <TextField
                  label={language === 'ar' ? 'اسم الشارع' : 'Street Name'}
                  variant="outlined"
                  fullWidth
                  value={streetName}
                  onChange={(e) => setStreetName(e.target.value)}
                  required
                />
                <TextField
                  label={language === 'ar' ? 'رقم المبنى' : 'Building Number'}
                  variant="outlined"
                  fullWidth
                  value={buildingNumber}
                  onChange={(e) => setBuildingNumber(e.target.value)}
                  required
                  style={{ marginTop: 10 }}
                />
                <TextField
                  label={language === 'ar' ? 'رقم الطابق (اختياري)' : 'Floor Number (Optional)'}
                  variant="outlined"
                  fullWidth
                  value={floorNumber}
                  onChange={(e) => setFloorNumber(e.target.value)}
                  style={{ marginTop: 10 }}
                />
                <TextField
                  label={language === 'ar' ? 'رقم الشقة (اختياري)' : 'Apartment Number (Optional)'}
                  variant="outlined"
                  fullWidth
                  value={apartmentNumber}
                  onChange={(e) => setApartmentNumber(e.target.value)}
                  style={{ marginTop: 10 }}
                />
                <TextField
                  label={language === 'ar' ? 'رقم الهاتف' : 'Phone Number'}
                  variant="outlined"
                  fullWidth
                  value={phoneNumber}
                  onChange={(e) => setPhoneNumber(e.target.value)}
                  required
                  style={{ marginTop: 10 }}
                />
              </DialogContent>
              <DialogActions>
                <Button onClick={() => setOpenAddAddress(false)}>{getLabel('cancel')}</Button>
                <Button onClick={handleAddAddress} variant="contained" color="primary">
                  {getLabel('add')}
                </Button>
              </DialogActions>
            </Dialog>
          </div>

          <div className="col-md-3 col-12 p-3 pt-md-0 pt-3">
            <div className="row align-items-center px-3 pb-3 border border-1 rounded-3">
              <h5 className="color-most-used py-3 border-bottom border-1 col-12 fw-bold">
                {language === 'ar' ? 'الطلب' : 'Order'}
              </h5>
              {cart.cart_item.map((item, index) => {
                let productdata, offerdata
                if (item.product_id) {
                  productdata = products.find((product) => product.id === item.product_id)
                } else {
                  offerdata = offers.find((offer) => offer.id === item.offer_id)
                }
                return (
                  <div
                    key={index}
                    className="col-12 d-flex justify-content-between align-items-center py-3 mb-2 rounded-3"
                    style={{
                      background: '#f9f9fb',
                      boxShadow: '0 1px 4px rgba(0,0,0,0.04)',
                      border: '1px solid #ececec',
                    }}
                  >
                    <div className="d-flex flex-column col-7">
                      <span className="fw-bold color-most-used" style={{ fontSize: '1rem' }}>
                        {productdata
                          ? productdata.name
                          : offerdata
                            ? language === 'ar'
                              ? offerdata.offer_name_ar
                              : offerdata.offer_name_en
                            : ''}
                      </span>
                      <span className="text-secondary" style={{ fontSize: '0.9rem' }}>
                        {item.quantity} × {item.piece_price || 0}{' '}
                        {language === 'ar' ? 'ج.م' : 'EGP'}
                      </span>
                    </div>
                    <div className="col-3 text-end">
                      <span className="fw-bold text-danger" style={{ fontSize: '1.1rem' }}>
                        {(item.piece_price || 0) * (item.quantity || 0)}{' '}
                        {language === 'ar' ? 'ج.م' : 'EGP'}
                      </span>
                    </div>
                    <div className="col-2 text-end">
                      <Button
                        variant="outlined"
                        color="error"
                        size="small"
                        onClick={() => handleDeleteCartItem(item)}
                        style={{
                          minWidth: 0,
                          padding: '6px 10px',
                          borderRadius: '50%',
                          borderColor: '#ff4d4f',
                          background: '#fff',
                          transition: 'background 0.2s',
                        }}
                        title={language === 'ar' ? 'حذف' : 'Delete'}
                      >
                        <span role="img" aria-label="delete" style={{ fontSize: '1.2rem' }}>
                          🗑️
                        </span>
                      </Button>
                    </div>
                  </div>
                )
              })}
              <div className="col-12 d-flex border-top border-1 justify-content-between py-2">
                <h6 className="color-most-used m-0 p-0 col-6">
                  {language === 'ar' ? 'الشحن' : 'Shipping'}
                </h6>
                <h6
                  className={`text-secondary m-0 col-6 ${language === 'ar' ? 'text-start' : 'text-end'}`}
                >
                  {language === 'ar' ? 'ج.م' : 'EGP'}
                  <span className="fw-bold mx-2 text-danger">
                    {isNaN(parseFloat(shipping)) ? '0' : parseFloat(shipping).toFixed(2)}
                  </span>
                </h6>
              </div>
              <div className="col-12 d-flex border-top border-1 justify-content-between py-2">
                <h6 className="color-most-used col-6">
                  {language === 'ar' ? 'المجموع الكلي' : 'Total'}
                </h6>
                <h6
                  className={`text-secondary m-0 col-6 ${language === 'ar' ? 'text-start' : 'text-end'}`}
                >
                  {language === 'ar' ? 'ج.م' : 'EGP'}
                  <span className="fw-bold mx-2 text-danger">
                    {(
                      cart.cart_item.reduce(
                        (total, item) => total + (item.piece_price || 0) * (item.quantity || 0),
                        0,
                      ) + (isNaN(parseFloat(shipping)) ? 0 : parseFloat(shipping))
                    ).toFixed(2)}
                  </span>
                </h6>
              </div>
              <Button
                form="checkout-form"
                type="submit"
                variant="contained"
                className="rounded-3 bg-most-used text-light col-12 p-2"
                onClick={() => {
                  addOrder()
                }}
              >
                {language === 'ar' ? 'إرسال' : 'Submit'}
              </Button>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

export default Checkout
