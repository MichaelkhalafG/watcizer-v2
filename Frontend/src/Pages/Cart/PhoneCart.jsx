import { useState, useContext } from "react";
import { MyContext } from "../../Context/Context";
import { Button, Rating, MenuItem, Select, FormControl, Alert, Snackbar } from "@mui/material";
import { CiCircleRemove } from "react-icons/ci";
import emptyCart from "../../assets/images/emptyCart.svg";
import { Link, useNavigate } from "react-router-dom";
import useCart, { getItemKey } from "../../Hooks/useCart";
import LoginModal from "../Auth/Login/LoginModal";
import axios from "axios";

function PhoneCart() {
    const {
        language,
        shippingPrices,
        setShippingName,
        productsCount,
        total_cart_price,
        shipping,
        setShipping,
        user_id,
        shippingid,
        setShippingid,
        windowWidth,
        products,
        offers,
    } = useContext(MyContext);

    const { cart, removeItem, updateQuantity } = useCart();
    const navigate = useNavigate();

    const [loginModalOpen, setLoginModalOpen] = useState(false);
    const [alertMessage, setAlertMessage] = useState("");
    const [alertType, setAlertType] = useState("info");
    const [openAlert, setOpenAlert] = useState(false);

    const showAlert = (message, type) => {
        setAlertMessage(message);
        setAlertType(type);
        setOpenAlert(true);
    };

    const handleQuantityChange = (item, value) => {
        const currentQty = item.quantity || 1;
        const newQty = currentQty + value;
        if (newQty > 0) {
            const identifier = getItemKey(item);
            updateQuantity(identifier, newQty);
        }
    };

    const getOfferRating = (offer, ratings) => {
        const productRatings = (ratings || []).filter((r) => r.id === offer.id);
        return productRatings.length > 0
            ? productRatings.reduce((acc, r) => acc + r.rating, 0) / productRatings.length
            : null;
    };

    const handleChange = (event) => {
        const selectedId = event.target.value;
        setShippingid(selectedId);
        const selectedShipping = shippingPrices.find(city => city.id === selectedId);
        if (selectedShipping) {
            setShipping(selectedShipping.Price.toString());
            setShippingName(language === 'ar' ? selectedShipping.GovernorateAr : selectedShipping.GovernorateEn);
        }
    };

    const handleRemoveItem = (item) => {
        const identifier = getItemKey(item);
        removeItem(identifier);
        showAlert(language === "ar" ? "تم ازالة المنتج من السلة" : "The product has been removed from the cart", "success");
    };

    const goToCheckout = async () => {
        if (user_id === null) {
            showAlert(language === "ar" ? "يجب عليك تسجيل الدخول للذهاب الي صفحة الدفع" : "You must log in to go to the checkout page", "error");
            navigate("/login");
            return;
        }
        if (shippingid === null) {
            showAlert(language === "ar" ? "يجب عليك اختيار مدينة الشحن" : "You must select a shipping city", "error");
            return;
        }
        try {
            const apiCode = "NbmFylY0vcwnhxUrm1udMgcX1MtPYb4QWXy1EKqVenm6uskufcXKeHh5W4TM5Iv0";
            const userId = user_id;

            for (const item of cart.cart_item) {
                await axios.post(`https://dash.watchizereg.com/api/add_to_cart`, {
                    user_id: userId,
                    product_id: item.product_id,
                    offer_id: item.offer_id,
                    quantity: item.quantity,
                    piece_price: parseInt(item.piece_price),
                    color_band: item.color_band,
                    type_stock: item.type_stock,
                    color_dial: item.color_dial,
                    total_price: item.piece_price * item.quantity
                }, {
                    headers: {
                        "Api-Code": apiCode
                    }
                });
            }
            navigate("/checkout");
        } catch {
            // handle error if needed
        }
    };

    return (
        <div className="container-fluid px-2 py-3">
            <Snackbar open={openAlert} autoHideDuration={3000} onClose={() => setOpenAlert(false)}
                anchorOrigin={{ vertical: windowWidth >= 768 ? "bottom" : "top", horizontal: windowWidth >= 768 ? "right" : "left" }}
            >
                <Alert severity={alertType} onClose={() => setOpenAlert(false)}>
                    {alertMessage}
                </Alert>
            </Snackbar>
            <div className="col-12 p-2">
                <h4 className="color-most-used fw-bold">
                    {language === "ar" ? "سلة المشتريات" : "Your Cart"}
                </h4>
                <h6 className="text-secondary mt-2">
                    {language === "ar" ? "هناك عدد " : "There are "}
                    <span className="text-danger fw-bold">{productsCount}</span>
                    {language === "ar" ? " من المنتجات في سلتك." : " products in your cart."}
                </h6>
            </div>
            {Array.isArray(cart.cart_item) && cart.cart_item.length > 0 ? (
                <>
                    <div className="row m-0 pb-5">
                        {cart.cart_item.map((item, index) => {
                            let isProduct = false;
                            let isOffer = false;
                            let productdata = null;
                            let offerdata = null;
                            let rating = null;
                            if (item.product_id !== null) {
                                productdata = products.find((product) => product.id === item.product_id);
                                rating = productdata && productdata.rating ? parseInt(productdata.rating) : 5;
                                isProduct = true;
                            } else {
                                offerdata = offers.find((offer) => String(offer.id) === String(item.offer_id));
                                rating = offerdata && offerdata.offer_rating ? parseInt(getOfferRating(offerdata, offerdata.offer_rating)) : 5;
                                isOffer = true;
                            }
                            const piecePrice = item.piece_price ? parseFloat(item.piece_price).toFixed(2) : "0.00";
                            const totalPrice = (parseFloat(item.piece_price || 0) * (item.quantity || 1)).toFixed(2);
                            return (
                                <div key={index} className="col-12 mb-3">
                                    <div className="card shadow-sm rounded-4 p-2 d-flex flex-row align-items-center">
                                        <div style={{ minWidth: 80, maxWidth: 80 }}>
                                            {isProduct && productdata && (
                                                <img
                                                    src={productdata.image}
                                                    alt={productdata.name}
                                                    loading="lazy"
                                                    className="rounded"
                                                    style={{ width: "70px", height: "70px", objectFit: "cover" }}
                                                />
                                            )}
                                            {isOffer && offerdata && offerdata.image && (
                                                <img
                                                    src={offerdata.image}
                                                    alt={language === 'ar' ? offerdata.offer_name_ar : offerdata.offer_name_en}
                                                    loading="lazy"
                                                    className="rounded"
                                                    style={{ width: "70px", height: "70px", objectFit: "cover" }}
                                                />
                                            )}
                                        </div>
                                        <div className="flex-grow-1 ms-3">
                                            <h6 className="color-most-used fw-bold mb-1" style={{ fontSize: "1rem" }}>
                                                {isProduct && productdata
                                                    ? productdata.name
                                                    : isOffer && offerdata
                                                        ? (language === 'ar' ? offerdata.offer_name_ar : offerdata.offer_name_en)
                                                        : ''}
                                            </h6>
                                            <Rating
                                                name="read-only"
                                                value={rating || 5}
                                                size="small"
                                                readOnly
                                            />
                                            <div className="d-flex align-items-center mt-2">
                                                <span className="me-2">{language === "ar" ? "الكمية:" : "Qty:"}</span>
                                                <Button
                                                    variant="outlined"
                                                    size="small"
                                                    onClick={() => handleQuantityChange(item, -1)}
                                                    disabled={(item.quantity || 1) <= 1}
                                                    sx={{ minWidth: '30px', padding: '0px' }}
                                                >-</Button>
                                                <input
                                                    type="text"
                                                    value={item.quantity || 1}
                                                    readOnly
                                                    className="mx-2 text-center"
                                                    style={{ width: '35px', border: '1px solid #ddd', borderRadius: '4px' }}
                                                />
                                                <Button
                                                    variant="outlined"
                                                    size="small"
                                                    onClick={() => handleQuantityChange(item, 1)}
                                                    disabled={
                                                        (isProduct && productdata && typeof productdata.stock === 'number' && parseInt(productdata.stock) <= parseInt(item.quantity)) ||
                                                        (isProduct && productdata && typeof productdata.market_stock === 'number' && productdata.market_stock > 0 && parseInt(productdata.market_stock) <= parseInt(item.quantity)) ||
                                                        (isOffer && offerdata && typeof offerdata.stock === 'number' && parseInt(offerdata.stock) <= parseInt(item.quantity))
                                                    }
                                                    sx={{ minWidth: '30px', padding: '0px' }}
                                                >+</Button>
                                            </div>
                                            <div className="d-flex mt-2">
                                                <div className="me-2">
                                                    <span style={{ fontSize: 12 }}>{language === "ar" ? "لون السوار:" : "Band:"}</span>
                                                    <div style={{
                                                        backgroundColor: item.color_band || "#f0f0f0",
                                                        width: 24, height: 24, borderRadius: 4,
                                                        border: '1px solid #ddd', display: 'inline-block', marginLeft: 4
                                                    }}>
                                                        {!item.color_band && <span style={{ fontSize: 10, color: '#666' }}>{language === 'ar' ? 'لا لون' : 'No'}</span>}
                                                    </div>
                                                </div>
                                                <div>
                                                    <span style={{ fontSize: 12 }}>{language === "ar" ? "لون الوجه:" : "Dial:"}</span>
                                                    <div style={{
                                                        backgroundColor: item.color_dial || "#f0f0f0",
                                                        width: 24, height: 24, borderRadius: 4,
                                                        border: '1px solid #ddd', display: 'inline-block', marginLeft: 4
                                                    }}>
                                                        {!item.color_dial && <span style={{ fontSize: 10, color: '#666' }}>{language === 'ar' ? 'لا لون' : 'No'}</span>}
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="d-flex align-items-center mt-2">
                                                <span className="me-2">{language === "ar" ? "السعر:" : "Price:"}</span>
                                                <span className="fw-bold color-most-used" style={{fontSize:"small"}}>{piecePrice} {language === "ar" ? "ج.م" : "EGP"}</span>
                                                <span className="mx-2">{language === "ar" ? "المجموع:" : "Subtotal:"}</span>
                                                <span className="fw-bold text-danger" style={{fontSize:"small"}}>{totalPrice} {language === "ar" ? "ج.م" : "EGP"}</span>
                                            </div>
                                            <div className="d-flex align-items-center mt-2">
                                                {item.type_stock && (
                                                    <span className={`badge ${item.type_stock === "Express" ? 'bg-black' : item.type_stock === "Market" ? "bg-success" : 'bg-danger'} p-2`}>
                                                        {item.type_stock}
                                                    </span>
                                                )}
                                                <Button
                                                    variant="contained"
                                                    className="rounded-circle ms-5 bg-danger text-light p-2"
                                                    sx={{ width: '36px', height: '36px', minWidth: '0', padding: 0 }}
                                                    onClick={() => handleRemoveItem(item)}
                                                >
                                                    <CiCircleRemove size={20} />
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                        <div className="col-12 mt-4 mb-5 pb-5">
                            <div className="row align-items-center px-3 border border-1 rounded-3">
                                <h6 className="color-most-used py-3 border-bottom border-1 col-12 fw-bold">
                                    {language === "ar" ? "مجموع السلة" : "CART TOTALS"}
                                </h6>
                                <div className="col-12 d-flex border-bottom border-1 justify-content-between py-2">
                                    <h6 className="color-most-used col-6">
                                        {language === "ar" ? "المجموع الكلي" : "Subtotal"}
                                    </h6>
                                    <h6 className={`text-secondary col-6 ${language === 'ar' ? 'text-start' : 'text-end'}`}>
                                        {language === "ar" ? "ج.م" : "EGP"}
                                        <span className="fw-bold mx-2 text-danger">
                                            {total_cart_price - shipping}
                                        </span>
                                    </h6>
                                </div>
                                <div className="col-12 d-flex border-bottom border-1 justify-content-between py-2">
                                    <h6 className="color-most-used d-flex align-items-center col-6">
                                        {language === "ar" ? "الشحن الي" : "Shipping to"}
                                    </h6>
                                    <div className="col-6">
                                        <FormControl fullWidth>
                                            <Select
                                                labelId="governorate-select-label"
                                                id="governorate-select"
                                                value={shippingid}
                                                onChange={handleChange}
                                                fullWidth
                                            >
                                                {shippingPrices.map((city) => (
                                                    <MenuItem key={city.id} value={city.id}>
                                                        {language === 'ar' ? city.GovernorateAr : city.GovernorateEn}
                                                    </MenuItem>
                                                ))}
                                            </Select>
                                        </FormControl>
                                    </div>
                                </div>
                                <div className="col-12 d-flex border-bottom border-1 justify-content-between py-2">
                                    <h6 className="color-most-used col-6">
                                        {language === "ar" ? "الشحن" : "Shipping"}
                                    </h6>
                                    <h6 className={`text-secondary col-6 ${language === 'ar' ? 'text-start' : 'text-end'}`}>
                                        {language === 'ar' ? 'ج.م' : 'EGP'}
                                        <span className="fw-bold mx-2 text-danger">
                                            {shipping}
                                        </span>
                                    </h6>
                                </div>
                                <div className="col-12 d-flex border-bottom border-1 justify-content-between py-2">
                                    <h6 className="color-most-used col-6">
                                        {language === "ar" ? "المجموع الكلي" : "Total"}
                                    </h6>
                                    <h6 className={`text-secondary m-0 col-6 ${language === 'ar' ? 'text-start' : 'text-end'}`}>
                                        {language === 'ar' ? 'ج.م' : 'EGP'}
                                        <span className="fw-bold mx-2 text-danger">
                                            {total_cart_price}
                                        </span>
                                    </h6>
                                </div>
                                <div className="col-12 p-3">
                                    {user_id === null ? (
                                        <Button
                                            variant="contained"
                                            className="rounded-3 bg-most-used text-light col-12 p-2"
                                            onClick={() => setLoginModalOpen(true)}
                                        >
                                            {language === "ar" ? "تسجيل الدخول" : "Login"}
                                        </Button>
                                    ) : (
                                        <Button
                                            variant="contained"
                                            className="rounded-3 bg-most-used text-light col-12 p-2"
                                            onClick={() => goToCheckout()}
                                        >
                                            {language === "ar" ? "الدفع" : "Checkout"}
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                </>
            ) : (
                <EmptyCartMessage language={language} />
            )}
            <Snackbar open={openAlert} autoHideDuration={3000} onClose={() => setOpenAlert(false)}
                anchorOrigin={{ vertical: windowWidth >= 768 ? "bottom" : "top", horizontal: windowWidth >= 768 ? "right" : "left" }}
            >
                <Alert severity={alertType} onClose={() => setOpenAlert(false)}>
                    {alertMessage}
                </Alert>
            </Snackbar>
            <LoginModal open={loginModalOpen} onClose={() => setLoginModalOpen(false)} onLoginSuccess={() => setLoginModalOpen(false)} />
        </div>
    );
}

function EmptyCartMessage({ language }) {
    return (
        <div className="row justify-content-center">
            <div className="col-12 d-flex justify-content-center">
                <img src={emptyCart} loading="lazy" alt="empty cart" className="col-6" />
            </div>
            <h4 className="text-center fw-bold color-most-used mt-1">
                {language === "ar" ? "السلة فارغة" : "Your Cart is currently empty"}
            </h4>
            <h6 className="text-center text-secondary">
                {language === "ar" ? "الرجاء اختيار المنتجات التي ترغب في شرائها" : "Please choose the products you want to buy"}
            </h6>
            <div className="col-12 d-flex justify-content-center mt-3">
                <Link to={"/"} className="text-decoration-none col-6">
                    <Button
                        variant="contained"
                        className="rounded-pill bg-most-used text-light col-12 p-2"
                    >
                        {language === "ar" ? "تسوق الآن" : "Shop Now"}
                    </Button>
                </Link>
            </div>
        </div>
    );
}

export default PhoneCart;