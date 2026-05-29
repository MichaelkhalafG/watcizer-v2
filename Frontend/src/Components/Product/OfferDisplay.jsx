import { useContext, useEffect, useState, useCallback } from "react";
import { Rating, Button, TextField, Typography ,Alert ,Snackbar } from "@mui/material";
import { useParams, Link } from "react-router-dom";
import { InnerImageZoom } from "react-inner-image-zoom";
import useCart , {getItemKey} from "../../Hooks/useCart";
import "react-inner-image-zoom/lib/InnerImageZoom/styles.css";
import "./Product.css";
import PropTypes from "prop-types";
import DOMPurify from "dompurify";
import { MyContext } from "../../Context/Context";
import axios from "axios";
import defimg from "../../assets/images/offer.webp"

function OfferDisplay() {
    const { id } = useParams();
    const { language, users, offers, products, windowWidth, handleAddTowishlist, user_id, tables,
        // fetchCart, setCart
    } = useContext(MyContext);
    const { cart, addItem, updateQuantity } = useCart();
    const [offer, setOffer] = useState(null);
    const [selectedImage, setSelectedImage] = useState("");
    const [ratings, setRatings] = useState([]);
    const [stock, setstock] = useState();
    const [quantity, setQuantity] = useState(1);
    const [ratingsOpen, setRatingsOpen] = useState(false);
    const [newRating, setNewRating] = useState({ value: 0, comment: "" });
    const handleRatingClick = () => setRatingsOpen((prev) => !prev);
    const product = products?.find((p) => p.id === offer?.main_product_id);
    const [alertMessage, setAlertMessage] = useState("");
    const [alertType, setAlertType] = useState("info");
    const [openAlert, setOpenAlert] = useState(false);

    const showAlert = useCallback((message, type) => {
        setOpenAlert(true);
        setAlertMessage(message);
        setAlertType(type);
    }, []);

    const handleQuantityChange = (change) => {
        setQuantity((prev) => Math.max(1, Math.min(prev + change, stock)));
    };

    useEffect(() => {
        setOffer(offers?.find((o) => o.id === parseInt(id)));
    }, [id, offers]);

    const renderDetail = (labelEn, labelAr, value, fs, col) => (
        <div className={`${col} mb-2`}>
            <p className={`fw-bold text-secondary ${language === 'ar' ? "text-end" : "text-start"}`} style={{ fontSize: fs }}>
                <span className={`${language === "ar" ? "ms-2" : "me-2"}`}>
                    {language === "ar" ? `${labelAr}:` : `${labelEn}:`}
                </span>
                {value || "-"}
            </p>
        </div>
    );

    const handleAddToCart = useCallback((id) => {
        const piecePrice = parseFloat(product.sale_price_after_discount);
        const totalQty = quantity;
      
        if (isNaN(piecePrice) || piecePrice <= 0) {
          showAlert(language === "ar" ? "حدث خطأ في السعر." : "There was an error with the price.", "warning");
          return;
        }
      
        if (stock <= 0) {
          showAlert(language === "ar" ? "المنتج غير متوفر حالياً." : "This product is currently out of stock.", "warning");
          return;
        }
      
        const identifier = `offer_${product.id}`;
      
        const existingItem = cart.cart_item.find(
          (item) => getItemKey(item) === identifier
        );
      
        if (existingItem) {
          const newQuantity = existingItem.quantity + totalQty;
          if (newQuantity > stock) {
            showAlert(language === "ar" ? "الكمية المطلوبة أكبر من المتوفر." : "Requested quantity exceeds available stock.", "warning");
            return;
          }
          updateQuantity(identifier, newQuantity);
        } else {
          if (totalQty > stock) {
            showAlert(language === "ar" ? "الكمية المطلوبة أكبر من المتوفر." : "Requested quantity exceeds available stock.", "warning");
            return;
          }
          addItem({
            offer_id: id,
            quantity: totalQty,
            piece_price: piecePrice,
          });
        }
      
        showAlert(language === "ar" ? "تمت الإضافة إلى السلة!" : "Added to cart!", "success");
      }, [
        language,
        product,
        quantity,
        showAlert,
        addItem,
        updateQuantity,
        cart,
        stock,
      ]);

    // const handleAddToCart = () => {
    //     if (!user_id) {
    //         alert(language === "ar" ? "يجب تسجيل الدخول أولاً!" : "You must login first!");
    //     } else {
    //         const piecePrice = parseInt(offer.price, 10);
    //         const totalPrice = piecePrice * quantity;

    //         if (isNaN(totalPrice) || totalPrice <= 0) {
    //             // console.error("Invalid total price calculation.");
    //             alert(language === "ar" ? "حدث خطأ في حساب السعر الإجمالي." : "There was an error calculating the total price.");
    //             return;
    //         }

    //         const payload = {
    //             user_id: user_id,
    //             offer_id: offer.id,
    //             quantity: quantity,
    //             piece_price: piecePrice,
    //             total_price: totalPrice,
    //         };

    //         axios.post("https://dash.watchizereg.com/api/add_to_cart", payload, {
    //             headers: {
    //                 "Api-Code": "NbmFylY0vcwnhxUrm1udMgcX1MtPYb4QWXy1EKqVenm6uskufcXKeHh5W4TM5Iv0"
    //             }
    //         })
    //             .then(() => {
    //                 alert(language === "ar" ? "تمت الإضافة إلى السلة!" : "Added to the cart!");
    //                 fetchCart(user_id, products, offers, language, setCart);
    //             })
    //             .catch(() => {
    //                 // console.error("Error adding to cart:", error);
    //                 alert(language === "ar" ? "حدث خطأ أثناء الإضافة إلى السلة." : "An error occurred while adding to the cart.");
    //             });
    //     }
    // };


    const fetchRatings = useCallback(async () => {
        try {
            const response = await axios.get(
                "https://dash.watchizereg.com/api/all_offer_rating",
                {
                    headers: {
                        "Api-Code": "NbmFylY0vcwnhxUrm1udMgcX1MtPYb4QWXy1EKqVenm6uskufcXKeHh5W4TM5Iv0",
                    },
                }
            );
            const productRatings = response.data.filter((r) => r.offer_id === offer?.id);
            setRatings(productRatings);
        } catch {
            // console.error("Error fetching ratings");
        }
    }, [offer]);

    useEffect(() => {
        if (offer) {
            setSelectedImage(offer.image || offer.images?.[0] || "");
            fetchRatings();

            setstock(parseInt(offer.stock));
        }
    }, [offer, offers, fetchRatings]);

    const handleRatingSubmit = async (value, comment) => {
        if (!user_id) {
            alert(language === "ar" ? "يجب تسجيل الدخول أولاً!" : "You must login first!");
        } else {
            const sanitizedComment = DOMPurify.sanitize(comment);

            if (value && sanitizedComment.trim()) {
                try {
                    await axios.post(
                        "https://dash.watchizereg.com/api/add_offer_rating",
                        null,
                        {
                            params: {
                                offer_id: offer.id,
                                rating: value,
                                comment: sanitizedComment,
                                user_id: user_id,
                            },
                            headers: {
                                "Api-Code": "NbmFylY0vcwnhxUrm1udMgcX1MtPYb4QWXy1EKqVenm6uskufcXKeHh5W4TM5Iv0",
                            },
                        }
                    );

                    await fetchRatings();
                    setNewRating({ value: 0, comment: "" });
                } catch {
                    // console.error("Error submitting rating:", error);
                    alert(
                        language === "ar"
                            ? "حدث خطأ أثناء إرسال التقييم. يرجى المحاولة مرة أخرى."
                            : "An error occurred while submitting the rating. Please try again."
                    );
                }
            } else {
                alert(
                    language === "ar"
                        ? "يرجى إدخال تقييم وتعليق صحيح"
                        : "Please enter a valid rating and comment"
                );
            }
        }
    };
    const render_product_ids = (labelEn, ids, labelAr, col = 'col-md-3 col-4') => (
    ids.map((id) => {
        const product = products?.filter(p => p.id === id)[0];
        
        if (!product) return null;

        return (
            <div className={`${col} p-1`} key={id}>
                <Link to={`/product/${product?.product_title}`}>
                    <InnerImageZoom
                        src={product?.image || defimg}
                        zoomSrc={product?.image || defimg}
                        alt={`product ${id}`} 
                        style={{
                            width: "100%",
                            borderRadius: "8px",
                            objectFit: "cover",
                            maxHeight: "300px",
                        }}
                        className="col-12 border border-1 rounded-3"
                        zoomType="hover"
                        zoomPreload={true}
                        zoomScale={1.2}
                    />
                    
                </Link>
            </div>
        );
    })
    );
    
    if (!offer) {
    return <p>Offer not found</p>; 
}

    return (
        <div className="container">
            <Snackbar open={openAlert} autoHideDuration={3000} onClose={() => setOpenAlert(false)}
                            anchorOrigin={{ vertical: windowWidth >= 768 ? "bottom" : "top", horizontal: windowWidth >= 768 ? "right" : "left" }}
                        >
                            <Alert severity={alertType} onClose={() => setOpenAlert(false)}>
                                {alertMessage}
                            </Alert>
                        </Snackbar>
            <div className={`row ${windowWidth >= 768 ? 'border-bottom' : ""}  border-2 ps-1 p-4 pb-2 product-header mb-3`}>
                <div className={`col-12 ${language === 'ar' ? "text-end" : "text-start"}`}>
                    <h3 className="fw-bold">{language === "ar" ? offer.offer_name_ar : offer.offer_name_en}</h3>
                </div>
                <div className="col-4">
                    {renderDetail("Brand", "البراند", tables?.categoryTypes?.find(c => c.id === offer.category_type_id)?.category_type_name, windowWidth >= 768 ? "Medium" : "small", "col-12")}
                </div>
                {/* <div className="col-4">
                    {renderDetail('Price', 'السعر', offer.sale_price_after_discount, windowWidth >= 768 ? "Medium" : "small", "col-12")}
                </div> */}
                <div className="col-4">
                    <Rating
                        name="read-only"
                        value={Math.round(offer.average_rate === null ? 5 : offer.average_rate)}
                        size="small"
                        readOnly
                    />
                </div>
            </div>
            <div className="row product-details">
                <div className="col-md-4 col-12 product-images">
                    <div className="selected-image mb-3 d-flex justify-content-center">
                        {selectedImage && (
                            <InnerImageZoom
                                src={selectedImage ||defimg}
                                zoomSrc={selectedImage || defimg}
                                alt="Selected Product"
                                style={{
                                    width: "100%",
                                    borderRadius: "8px",
                                    objectFit: "cover",
                                    maxHeight: "300px",
                                }}
                                zoomType="hover"
                                zoomPreload={true}
                                zoomScale={2}
                            />
                        )}
                    </div>
                    <div className="d-flex mt-3 gap-2 justify-content-center flex-wrap">
                        {offer?.image && (
                            <img
                                src={offer.image}
                                alt="Main Thumbnail"
                                onClick={() => setSelectedImage(offer.image)}
                                style={{
                                    width: "60px",
                                    height: "60px",
                                    objectFit: "cover",
                                    borderRadius: "4px",
                                    border: offer.image === selectedImage ? "2px solid #262626" : "1px solid #ddd",
                                    cursor: "pointer",
                                    boxShadow: offer.image === selectedImage ? "0px 4px 10px rgba(0, 0, 0, 0.2)" : "none",
                                }}
                                className="thumbnail"
                            />
                        )}
                        {offer?.images?.map((image, index) => (
                            <img
                                key={image}
                                src={image}
                                alt={`Thumbnail ${index + 1}`}
                                onClick={() => setSelectedImage(image)}
                                style={{
                                    width: "60px",
                                    height: "60px",
                                    objectFit: "cover",
                                    borderRadius: "4px",
                                    border: image === selectedImage ? "2px solid #262626" : "1px solid #ddd",
                                    cursor: "pointer",
                                    boxShadow: image === selectedImage ? "0px 4px 10px rgba(0, 0, 0, 0.2)" : "none",
                                }}
                                className="thumbnail"
                            />
                        ))}
                    </div>
                </div>

                <div className="col-md-8 col-12 row product-info">
                    <h5 className="">{language === 'ar' ? 'التفاصيل' : 'Details'}</h5>
                    <p className="text-secondary " style={{ fontSize: 'small' }}>
                        {language === 'ar' ? offer.long_description_ar : offer.long_description_en}
                    </p>
                    <div className="d-flex col-12 my-3 align-items-center">
                            <span className="color-most-used fw-bold me-2 fs-large" style={{ fontSize: 'large' }}>
                                {Math.round(offer.sale_price_after_discount)} {language === 'ar' ? 'ج.م' : 'EGP'}
                            </span>
                            <span className="text-muted text-decoration-line-through fs-large" style={{ fontSize: 'large' }}>
                                {Math.round(offer.selling_price)} {language === 'ar' ? 'ج.م' : 'EGP'}
                            </span>
                        </div>
                    <div className='col-12 d-flex flex-column p-1'>
                        <p className="fw-bold px-3 text-secondary col-12" style={{ fontSize: "large" }}>
                            {language === "ar" ? `المنتج الاساسي :` : `Main Product :`}
                        </p>
                        <Link to={`/product/${product?.product_title}`} className='col-12 d-flex justify-content-center' style={{
                            fontSize: "small"
                        }}>
                            <InnerImageZoom
                                    src={products?.find(o => o.id === offer.main_product_id)?.image|| defimg}
                                    zoomSrc={products?.find(o => o.id === offer.main_product_id)?.image || defimg}
                                    alt={`product ${offer.main_product_id}`}
                                    style={{
                                        width: "100%",
                                        borderRadius: "8px",
                                        objectFit: "cover",
                                        maxHeight: "300px",
                                }}
                                className='col-md-4 col-8 border border-1 rounded-3'
                                    zoomType="hover"
                                    zoomPreload={true}
                                    zoomScale={1.2}
                                />
                        </Link>
                    </div>
                    <div className='row m-0 col-12 justify-content-center p-3'>
                        <p className="fw-bold col-12 text-secondary" style={{ fontSize: "large" }}>
                            {language === "ar" ? `منتجات العرض :` : `Offer Products :`}
                        </p>
                        {render_product_ids('Gift Products', offer.gift_product_ids, 'منتجات العرض')}
                    </div>
                    <div className={`quantity-control col-6 d-flex align-items-center ${language === 'ar' ? "justify-content-end" : ""}`}>
                        <Button
                            variant="outlined"
                            size="small"
                            onClick={() => handleQuantityChange(-1)}
                            disabled={quantity <= 1}
                            sx={{ minWidth: '30px', padding: '5px' }}
                        >
                            -
                        </Button>
                        <input
                            type="text"
                            value={quantity}
                            readOnly
                            style={{
                                width: '40px',
                                textAlign: 'center',
                                margin: '0 10px',
                                border: '1px solid #ddd',
                                borderRadius: '4px',
                            }}
                        />
                        <Button
                            variant="outlined"
                            size="small"
                            onClick={() => handleQuantityChange(1)}
                            sx={{ minWidth: '30px', padding: '5px' }}
                        >
                            +
                        </Button>
                    </div>
                    <div className={`col-6 d-flex align-items-center ${language === 'ar' ? "justify-content-end" : ""}`}>
                        {stock && parseInt(stock) > 0 ? (
                            <span className="badge bg-success" style={{ fontSize: '0.9rem' }}>
                                {language === 'ar' ? 'متوفر' : 'In Stock'}
                            </span>
                        ) : (
                            <span className="badge bg-danger" style={{ fontSize: '0.9rem' }}>
                                {language === 'ar' ? 'غير متوفر' : 'Out of Stock'}
                            </span>
                        )}
                    </div>
                    <div className="mt-3 col-12 d-flex justify-content-between action-buttons">
                        <button
                            className={`col-6 btn btn-dark`}
                            onClick={()=>handleAddToCart(id)
                            }
                            disabled={stock <= 0}
                        >
                            {language === "ar" ? "أضف إلى السلة" : "Add to Cart"}
                        </button>

                        <button
                            className="btn btn-outline-danger col-5"
                            onClick={() => handleAddTowishlist(offer.id, "o")}
                        >
                            {language === "ar" ? "أضف إلى قائمة الرغبات" : "Add to Wish List"}
                        </button>
                    </div>
                </div>
            </div>

            <div className="ratings-section row align-items-center rounded-5 border border-2 p-md-5 p-3 mx-md-0 mx-2 mt-4">
                <Typography variant="h5" className="col-md-10 col-6">{language === "ar" ? "التقييمات" : "Ratings"}</Typography>
                <button
                    onClick={handleRatingClick}
                    className={`mt-3 col-md-2 col-6 btn ${ratingsOpen ? 'btn-danger' : 'btn-dark'} `}
                >
                    {ratingsOpen ? language === "ar" ? "اخفاء التقييمات" : "Close Ratings" : language === "ar" ? "عرض التقييمات" : "View Ratings"}
                </button>
                <div className={`rating-list col-12 ${ratingsOpen ? "" : "d-none"} row mt-3`}>
                    {ratings.length > 0 ? (
                        ratings.map((rating) => (
                            <div key={rating.id} className="rating-item col-md-6 col-12 mb-3">
                                <Rating name="read-only" value={rating.rating} readOnly size="small" />
                                <p>{rating.comment}</p>
                                <small className="me-3">by : {users?.find(u => u.id === rating.user_id)?.first_name}</small>
                                <small>{new Date(rating.created_at).toLocaleDateString()}</small>
                            </div>
                        ))
                    ) : (
                        <p>{language === "ar" ? "لا توجد تقييمات بعد" : "No ratings yet"}</p>
                    )}
                </div>
                <div className="add-rating mt-4">
                    <Typography variant="h6">{language === "ar" ? "إضافة تقييم" : "Add a Rating"}</Typography>
                    <div className="mt-2">
                        <Rating
                            name="new-rating"
                            value={newRating.value}
                            onChange={(e, value) => setNewRating((prev) => ({ ...prev, value }))}
                        />
                    </div>
                    <TextField
                        fullWidth
                        multiline
                        rows={3}
                        placeholder={language === "ar" ? "أضف تعليقك" : "Add your comment"}
                        value={newRating.comment}
                        onChange={(e) => setNewRating((prev) => ({ ...prev, comment: e.target.value }))}
                        variant="outlined"
                        className="mt-3"
                    />
                    <button
                        className="mt-2 btn btn-dark"
                        onClick={() => handleRatingSubmit(newRating.value, newRating.comment)}
                    >
                        {language === "ar" ? "إرسال التقييم" : "Submit Rating"}
                    </button>
                </div>
            </div>
        </div>
    );
}

OfferDisplay.propTypes = {
    offers: PropTypes.arrayOf(
        PropTypes.shape({
            id: PropTypes.number.isRequired,
            main_product_id: PropTypes.number.isRequired,
            gift_product_ids: PropTypes.arrayOf(PropTypes.number).isRequired,
            price: PropTypes.oneOfType([PropTypes.number, PropTypes.string]).isRequired,
            category_type_id: PropTypes.number,
            stock: PropTypes.number.isRequired,
            image: PropTypes.string.isRequired,
            average_rate: PropTypes.oneOfType([PropTypes.number, PropTypes.oneOf([null])]),
            offer_name_en: PropTypes.string.isRequired,
            offer_name_ar: PropTypes.string.isRequired,
            long_description: PropTypes.string,
            rating: PropTypes.oneOfType([PropTypes.number, PropTypes.oneOf([null])]),
        })
    ),
};

export default OfferDisplay;
