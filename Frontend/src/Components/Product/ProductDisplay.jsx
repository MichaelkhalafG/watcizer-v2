import { useContext, useEffect, useState, useCallback } from "react";
import { Rating, Button, TextField, Typography, Alert, Snackbar } from "@mui/material";
import { useParams } from "react-router-dom";
import useCart , {getItemKey} from "../../Hooks/useCart";
import InnerImageZoom from "react-inner-image-zoom";
import "react-inner-image-zoom/lib/InnerImageZoom/styles.css";
import "./Product.css";
import PropTypes from "prop-types";
import ProductSlider from "./ProductSlider";
import DOMPurify from "dompurify";
import { MyContext } from "../../Context/Context";
import axios from "axios";

function ProductDisplay() {
    const [alertMessage, setAlertMessage] = useState("");
    const [alertType, setAlertType] = useState("info");
    const [isfashion, setisfashion] = useState(false);
    const [openAlert, setOpenAlert] = useState(false);
    const [type_stock, settype_stock] = useState("");
    const [price, setPrice] = useState(0);
    const [pricebefore, setPriceBefore] = useState(0);
    const { name } = useParams();
    const { addItem, updateQuantity, cart } = useCart();
    const { language, users, products, user_id, windowWidth, handleAddTowishlist, Loader,
        // offers, setCart, fetchCart
    } = useContext(MyContext);
    const product = products.find((p) => p.name === name);
    const [realetedProducts, setRelatedProducts] = useState([]);
    const DialColor = product?.dial_color[0]?.color_value;
    const BandColor = product?.band_color[0]?.color_value;
    const [selectedDialColor, setSelectedDialColor] = useState(DialColor || null);
    const [selectedBandColor, setSelectedBandColor] = useState(BandColor || null);
    const [selectedImage, setSelectedImage] = useState("");
    const [ratings, setRatings] = useState([]);
    const [stock, setstock] = useState();
    const [quantity, setQuantity] = useState(1);
    const [totalRating, setTotalRating] = useState(5);
    const [ratingsOpen, setRatingsOpen] = useState(false);
    const [showFullDescription, setShowFullDescription] = useState(false);
    const [newRating, setNewRating] = useState({ value: 0, comment: "" });
    const handleRatingClick = () => setRatingsOpen((prev) => !prev);

    const handleQuantityChange = (change) => {
        setQuantity((prev) => Math.max(1, Math.min(prev + change, stock)));
    };

    const getShortDescription = (desc) => {
        if (!desc) return language === "ar" ? "لا يوجد وصف" : "No description available";
        const words = desc.split(" ");
        if (words.length <= 10) return desc;
        return words.slice(0, 10).join(" ") + "...";
    };

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
    const showAlert = useCallback((message, type) => {
        setAlertMessage(message);
        setAlertType(type);
        setOpenAlert(true);
    }, []);
    
    useEffect(() => {
        if (product) {
            product.category_type_name === "Watches" ? setisfashion(false) : setisfashion(true);
            setPrice(parseInt(product.sale_price_after_discount, 10));
            setPriceBefore(parseInt(product.selling_price, 10));
        }
    }, [product]);

    const handleAddToCart = useCallback(() => {
        const piecePrice = parseFloat(price);
        const totalQty = quantity;
    
        if (isNaN(piecePrice) || piecePrice <= 0) {
            showAlert(language === "ar" ? "حدث خطأ في السعر." : "There was an error with the price.", "warning");
            return;
        }
    
        if (stock <= 0) {
            showAlert(language === "ar" ? "المنتج غير متوفر حالياً." : "This product is currently out of stock.", "warning");
            return;
        }
    
        const identifier = `product_${product.id}`;
        const existingItem = cart.cart_item.find(item => getItemKey(item) === identifier);
    
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
                product_id: product.id,
                quantity: totalQty,
                piece_price: piecePrice,
                color_band: selectedBandColor,
                color_dial: selectedDialColor,
                type_stock: type_stock,
            });
        }
    
        showAlert(language === "ar" ? "تمت الإضافة إلى السلة!" : "Added to cart!", "success");
    }, [
        language,
        product.id,
        price,
        quantity,
        selectedBandColor,
        selectedDialColor,
        type_stock,
        showAlert,
        addItem,
        updateQuantity,
        cart,
        stock
    ]);
    

    // const handleAddToCart = () => {
    //     if (!user_id) {
    //         setAlertMessage(language === "ar" ? "يجب تسجيل الدخول أولاً!" : "You must login first!");
    //         setAlertType("warning");
    //         setOpenAlert(true);
    //     } else {
    //         const piecePrice = parseInt(price, 10);
    //         const totalPrice = piecePrice * quantity;

    //         if (isNaN(totalPrice) || totalPrice <= 0) {
    //             setAlertMessage(language === "ar" ? "حدث خطأ في حساب السعر الإجمالي." : "There was an error calculating the total price.");
    //             setAlertType("error");
    //             setOpenAlert(true);
    //             return;
    //         }

    //         const payload = {
    //             user_id: user_id,
    //             product_id: product.id,
    //             quantity: quantity,
    //             piece_price: piecePrice,
    //             color_band: selectedBandColor,
    //             color_dial: selectedDialColor,
    //             type_stock: type_stock,
    //             total_price: totalPrice,
    //         };

    //         axios.post("https://dash.watchizereg.com/api/add_to_cart", payload, {
    //             headers: {
    //                 "Api-Code": "NbmFylY0vcwnhxUrm1udMgcX1MtPYb4QWXy1EKqVenm6uskufcXKeHh5W4TM5Iv0"
    //             }
    //         })
    //             .then(() => {
    //                 setAlertMessage(language === "ar" ? "تمت الإضافة إلى السلة!" : "Added to the cart!");
    //                 setAlertType("success");
    //                 setOpenAlert(true);
    //                 fetchCart(user_id, products, offers, language, setCart);
    //             })
    //             .catch(() => {
    //                 // console.error("Error adding to cart:", error);
    //                 setAlertMessage(language === "ar" ? "حدث خطأ أثناء الإضافة إلى السلة." : "An error occurred while adding to the cart.");
    //                 setAlertType("error");
    //                 setOpenAlert(true);
    //             });
    //     }
    // };



    const renderColorDetail = (labelEn, labelAr, colors, fs, col, setColor) => (
        <div className={`${col} mb-2`}>
            <div className={`fw-bold text-secondary ${language === 'ar' ? "text-end" : "text-start"}`} style={{ fontSize: fs }}>
                <span className={`${language === "ar" ? "ms-2" : "me-2"} pb-2`}>
                    {language === "ar" ? `${labelAr} :` : `${labelEn} :`}
                </span>
                <div className={`d-flex gap-2 ${language === 'ar' ? "justify-content-end" : ""}`}>
                    {colors && colors.map((color, index) => (
                        <div
                            key={index}
                            onClick={() => setColor(color.color_value)}
                            style={{
                                backgroundColor: color.color_value || "#f0f0f0",
                                width: '30px',
                                height: '30px',
                                borderRadius: '50%',
                                border: selectedDialColor === color.color_value || selectedBandColor === color.color_value
                                    ? '2px solid #000'
                                    : '1px solid #ddd',
                                cursor: 'pointer',
                            }}
                            title={language === 'ar' ? color.color_name_ar : color.color_name_en}
                        />
                    ))}
                </div>
            </div>
        </div>
    );


    const fetchRatings = useCallback(async () => {
        try {
            const response = await axios.get(
                "https://dash.watchizereg.com/api/all_product_rating",
                {
                    headers: {
                        "Api-Code": "NbmFylY0vcwnhxUrm1udMgcX1MtPYb4QWXy1EKqVenm6uskufcXKeHh5W4TM5Iv0",
                    },
                }
            );
            const productRatings = response.data.filter((r) => r.product_id === product?.id);
            setRatings(productRatings);
        } catch {
            // console.error("Error fetching ratings:", error);
        }
    }, [product]);

    useEffect(() => {
        if (product?.image) {
            setSelectedImage(product.image);
        } else if (product?.images?.length) {
            setSelectedImage(product.images[0]);
        }
        if (product) {
            fetchRatings();
            const related = (products || [])
                .filter(
                    (p) =>
                        p.brand === product.brand &&
                        p.band_colors?.length > 0 &&
                        product.band_colors?.length > 0 &&
                        p.band_colors.some(color => product.band_colors.some(pc => pc.color_id === color.color_id)) &&
                        p.id !== product.id
                )
                .slice(0, 20);
            setRelatedProducts(related);
            if (product?.stock && product.stock > 0) {
                setstock(parseInt(product.stock));
                settype_stock("Express");
            } else if (product?.market_stock && product.market_stock > 0) {
                setstock(parseInt(product.market_stock));
                settype_stock("Market");
            } else {
                setstock(0);
            }
        }
    }, [product, products, fetchRatings]);


    useEffect(() => {
        if (ratings.length > 0) {
            const total = ratings.reduce((acc, r) => acc + r.rating, 0);
            setTotalRating(total / ratings.length);
        } else {
            setTotalRating(5);
        }
    }, [ratings]);


    const handleRatingSubmit = async (value, comment) => {
        if (!user_id) {
            showAlert(language === "ar" ? "يجب تسجيل الدخول أولاً!" : "You must login first!", "warning");
            return;
        }
        const sanitizedComment = DOMPurify.sanitize(comment);
        if (value && sanitizedComment.trim()) {
            try {
                await axios.post(
                    "https://dash.watchizereg.com/api/add_product_rating",
                    null,
                    {
                        params: {
                            product_id: product.id,
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
                showAlert(language === "ar" ? "تم إرسال التقييم بنجاح!" : "Rating submitted successfully!", "success");
            } catch {
                // console.error("Error submitting rating:", error);
                showAlert(
                    language === "ar"
                        ? "حدث خطأ أثناء إرسال التقييم. يرجى المحاولة مرة أخرى."
                        : "An error occurred while submitting the rating. Please try again.",
                    "error"
                );
            }
        } else {
            showAlert(
                language === "ar" ? "يرجى إدخال تقييم وتعليق صحيح" : "Please enter a valid rating and comment",
                "warning"
            );
        }
    };


    if (!product) {
        return (
            <Loader />
        )
    } else {
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
                        <h3 className="fw-bold">{product?.product_title || "-"}</h3>
                    </div>
                    <div className="col-4">
                        {renderDetail("Brand", "البراند", product?.brand, windowWidth >= 768 ? "Medium" : "small", "col-12")}
                    </div>
                    <div className="col-4">
                        {renderDetail("Type", "النوع", product?.category_type, windowWidth >= 768 ? "Medium" : "small", "col-12")}
                    </div>
                    <div className="col-4">
                        <Rating
                            name="read-only"
                            value={totalRating}
                            size="small"
                            readOnly
                        />
                    </div>
                </div>

                <div className="row product-details">
                    <div className="col-md-4 product-images">
                        <div className="selected-image mb-3 d-flex justify-content-center">
                            {selectedImage && (
                                <InnerImageZoom
                                    src={selectedImage}
                                    zoomSrc={selectedImage}
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
                            {product?.image && (
                                <img
                                    src={product.image}
                                    alt="Main Thumbnail"
                                    onClick={() => setSelectedImage(product.image)}
                                    style={{
                                        width: "60px",
                                        height: "60px",
                                        objectFit: "cover",
                                        borderRadius: "4px",
                                        border: product.image === selectedImage ? "2px solid #262626" : "1px solid #ddd",
                                        cursor: "pointer",
                                        boxShadow: product.image === selectedImage ? "0px 4px 10px rgba(0, 0, 0, 0.2)" : "none",
                                    }}
                                    className="thumbnail"
                                />
                            )}
                            {product?.images?.map((image, index) => (
                                <img
                                    key={index}
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

                    <div className="col-md-8 product-info">
                        <h5 className={`my-3 ${language === 'ar' ? "text-end" : "text-start"}`}>{language === "ar" ? "التفاصيل" : "Details"}</h5>
                        <p className={`text-secondary fw-bold  ${language === 'ar' ? "text-end" : "text-start"}`} style={{ fontSize: "large" }}>
                            {showFullDescription
                                ? (product?.long_description || (language === "ar" ? "لا يوجد وصف" : "No description available"))
                                : getShortDescription(product?.long_description)
                            }
                            {product?.long_description && product.long_description.split(" ").length > 10 && (
                                <button
                                    className="btn btn-link p-0 ms-2"
                                    style={{ fontSize: "small" }}
                                    onClick={() => setShowFullDescription((prev) => !prev)}
                                >
                                    {showFullDescription
                                        ? (language === "ar" ? "عرض أقل" : "Read Less")
                                        : (language === "ar" ? "اقرأ المزيد" : "Read More")}
                                </button>
                            )}
                        </p>
                        <div className="d-flex col-12 my-3 align-items-center">
                            <span className="color-most-used fw-bold me-2 fs-large" style={{ fontSize: 'large' }}>
                                {Math.round(price)} {language === 'ar' ? 'ج.م' : 'EGP'}
                            </span>
                            <span className="text-muted text-decoration-line-through fs-large" style={{ fontSize: 'large' }}>
                                {Math.round(pricebefore)} {language === 'ar' ? 'ج.م' : 'EGP'}
                            </span>
                        </div>
                        <div className="row">
                            {product?.grade && renderDetail("Grade", "التصنيف", product.grade, "small", "col-md-4 col-6")}
                            {product?.sub_type && renderDetail("Sub Type", "النوع الفرعي", product.sub_type, "small", "col-md-4 col-6")}
                            {product?.band_closure && renderDetail("Band Closure", "إغلاق السوار", product.band_closure, "small", "col-md-4 col-6")}
                            {product?.dial_display_type && renderDetail("Dial Display", "نوع عرض وجة الساعة", product.dial_display_type, "small", "col-md-4 col-6")}
                            {product?.case_shape && renderDetail("Case Shape", "شكل العلبة", product.case_shape, "small", "col-md-4 col-6")}
                            {product?.band_material && isfashion ? renderDetail("Material", "مادة الصنع", product.band_material, "small", "col-md-4 col-6") : renderDetail("Band Material", "مادة السوار", product.band_material, "small", "col-md-4 col-6")}
                            {product?.watch_movement && renderDetail("Watch Movement", "حركة الساعة", product.watch_movement, "small", "col-md-4 col-6")}
                            {product?.water_resistance && renderDetail("Water Resistance", "مقاومة الماء", `${product.water_resistance} ${product.water_resistance_size_type}`, "small", "col-md-4 col-6")}
                            {product?.case_thickness && renderDetail("Case Size", "حجم العلبة", `${product.case_thickness} ${product.case_size_type}`, "small", "col-md-4 col-6")}
                            {product?.band_length && renderDetail("Band Length", "طول السوار", `${product.band_length} ${product.band_size_type}`, "small", "col-md-4 col-6")}
                            {product?.band_width && renderDetail("Band Width", "عرض السوار", `${product.band_width} ${product.band_width_size_type}`, "small", "col-md-4 col-6")}
                            {product?.case_thickness && renderDetail("Case Thickness", "سمك العلبة", `${product.case_thickness} ${product.case_thickness_size_type}`, "small", "col-md-4 col-6")}
                            {product?.watch_height && renderDetail("Watch Height", "ارتفاع الساعة", `${product.watch_height} ${product.watch_height_size_type}`, "small", "col-md-4 col-6")}
                            {product?.watch_width && renderDetail("Watch Width", "عرض الساعة", `${product.watch_width} ${product.watch_width_size_type}`, "small", "col-md-4 col-6")}
                            {product?.watch_length && renderDetail("Watch Length", "طول الساعة", `${product.watch_length} ${product.watch_length_size_type}`, "small", "col-md-4 col-6")}
                            {product?.dial_glass_material && renderDetail("Dial Glass Material", "مادة زجاج الوجة", product.dial_glass_material, "small", "col-md-4 col-6")}
                            {product?.dial_case_material && renderDetail("Dial Case Material", "مادة اطار الوجة", product.dial_case_material, "small", "col-md-4 col-6</div>")}
                            {product?.country && renderDetail("Country of Origin", "بلد الصنع", product.country, "small", "col-md-4 col-6")}
                            {product?.stone && renderDetail("Stone", "الحجر", product.stone, "small", "col-md-4 col-6")}
                            {product?.features?.length > 0 && renderDetail("Features", "الميزات", product.features.join(", "), "small", "col-md-4 col-6")}
                            {product?.gender?.length > 0 && renderDetail("Gender", "الجنس", product.gender.join(", "), "small", "col-md-4 col-6")}
                            <div className={`fw-bold text-secondary mb-1 col-12 ${language === 'ar' ? "text-end" : "text-start"}`} style={{ fontSize: 'medium' }}>
                                {language === 'ar' ? 'اختر اللون' : 'Chosse colors'}
                            </div>
                            {product?.dial_color.length > 0 && renderColorDetail("Dial Color", "لون وجة الساعة", product.dial_color, "small", "col-md-4 col-6", setSelectedDialColor)}
                            {product?.band_color.length > 0 && isfashion ? renderColorDetail("Color", "الون", product.band_color, "small", "col-md-4 col-6", setSelectedBandColor) : renderColorDetail("Band Color", "لون السوار", product.band_color, "small", "col-md-4 col-6", setSelectedBandColor)}
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
                                {stock &&
                                    <span className={`badge ${parseInt(product.stock) > 0 ? 'bg-black' : parseInt(product.market_stock) > 0 ? "bg-success" : 'bg-danger'} col-md-8 col-12 p-2`}>
                                        {language === 'ar' ? (parseInt(product.stock) > 0 ? 'اكسبريس' : parseInt(product.market_stock) > 0 ? "ماركت" : 'غير متوفر')
                                            : (parseInt(product.stock) > 0 ? 'Express' : parseInt(product.market_stock) > 0 ? "Market Place" : 'Out of Stock')}
                                    </span>
                                }
                            </div>
                        </div>

                        <div className="mt-3 col-12 d-flex justify-content-between action-buttons">
                            <button
                                className={`col-6 btn btn-dark`}
                                onClick={handleAddToCart}
                                disabled={stock <= 0}
                            >
                                {language === "ar" ? "أضف إلى السلة" : "Add to Cart"}
                            </button>

                            <button
                                className="btn btn-outline-danger col-5"
                                onClick={() => handleAddTowishlist(product.id, "p")}
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
                                    <small className="me-3">by : {users.find(u => u.id === rating.user_id)?.first_name}</small>
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
                <div className="row justify-content-center">
                    <div className="related-products col-md-11 lato-regular mt-4">
                        {realetedProducts && <ProductSlider
                            text={{
                                title: { en: "Related Product", ar: "المنتجات ذات الصلة" },
                                description: { en: "Products similar to the product you chose", ar: "منتجات مشابهة للمنتج الذي اخترته" }
                            }}
                            gradeproducts={realetedProducts}
                        />}
                        {/* {console.log(realetedProducts)} */}
                    </div>
                </div>
            </div>
        );
    }
}

ProductDisplay.propTypes = {
    products: PropTypes.arrayOf(
        PropTypes.shape({
            id: PropTypes.number.isRequired,
            name: PropTypes.string.isRequired,
            product_title: PropTypes.string.isRequired,
            model_name: PropTypes.string,
            long_description: PropTypes.string.isRequired,
            short_description: PropTypes.string.isRequired,
            selling_price: PropTypes.string.isRequired,
            sale_price_after_discount: PropTypes.string.isRequired,
            percentage_discount: PropTypes.string.isRequired,
            stock: PropTypes.number.isRequired,
            category_type_name: PropTypes.string.isRequired,
            rate: PropTypes.number,
            image: PropTypes.string,
            images: PropTypes.arrayOf(PropTypes.string),
            category_type: PropTypes.string.isRequired,
            brand: PropTypes.string.isRequired,
            grade: PropTypes.string,
            sub_type: PropTypes.string.isRequired,
            market_stock: PropTypes.number,
            dial_color: PropTypes.arrayOf(
                PropTypes.shape({
                    color_id: PropTypes.number,
                    color_value: PropTypes.string,
                    color_name_ar: PropTypes.string,
                    color_name_en: PropTypes.string,
                })
            ),
            band_color: PropTypes.arrayOf(
                PropTypes.shape({
                    color_id: PropTypes.number,
                    color_value: PropTypes.string,
                    color_name_ar: PropTypes.string,
                    color_name_en: PropTypes.string,
                })
            ),
            band_closure: PropTypes.string,
            dial_display_type: PropTypes.string,
            case_shape: PropTypes.string,
            band_material: PropTypes.string,
            watch_movement: PropTypes.string,
            water_resistance_size_type: PropTypes.string,
            water_resistance: PropTypes.string,
            case_size_type: PropTypes.string,
            case: PropTypes.string,
            band_size_type: PropTypes.string,
            band_length: PropTypes.string,
            band_width_size_type: PropTypes.string,
            band_width: PropTypes.string,
            case_thickness_size_type: PropTypes.string,
            case_thickness: PropTypes.string,
            watch_height_size_type: PropTypes.string,
            watch_width_size_type: PropTypes.string,
            watch_length_size_type: PropTypes.string,
            dial_glass_material: PropTypes.string,
            watch_height: PropTypes.string,
            watch_width: PropTypes.string,
            watch_length: PropTypes.string,
            dial_case_material: PropTypes.string,
            country: PropTypes.string,
            stone: PropTypes.string,
            features: PropTypes.arrayOf(PropTypes.string),
            gender: PropTypes.arrayOf(PropTypes.string),
        })
    ),
};

export default ProductDisplay;
