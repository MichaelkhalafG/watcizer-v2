import { useContext } from "react";
import { MyContext } from "../../Context/Context";
import { Button, Rating } from "@mui/material";
import { FaEye } from "react-icons/fa";
import { CiCircleRemove } from "react-icons/ci";
import emptyWishList from "../../assets/images/emptywishlist.svg";
import { Link } from "react-router-dom";
import axios from "axios";

function PhoneWishList() {
    const { language, wishList, setwishList, WishListCount } = useContext(MyContext);
    const handleRemoveItem = async (itemId) => {
        try {
            const apiCode = "NbmFylY0vcwnhxUrm1udMgcX1MtPYb4QWXy1EKqVenm6uskufcXKeHh5W4TM5Iv0";
            const response = await axios.delete(`https://dash.watchizereg.com/api/delete_wishlist/${itemId}`, {
                headers: { "Api-Code": apiCode }
            });

            if (response.status === 200) {
                setwishList((prevCart) => prevCart.filter((item) => item.id !== itemId));
            } else {
                // console.error("Failed to remove item from wishlist", response.data);
            }
        } catch {
            // console.error("Error removing item from wishlist:", error);
        }
    };
    return (
        <div className="row m-0 py-3 px-md-4 px-1">
            <div className="col-12 p-3">
                <h4 className="color-most-used fw-bold">
                    {language === "ar" ? "قائمة الرغبات" : "Your WishList"}
                </h4>
                <h6 className="text-secondary mt-2">
                    {language === "ar" ? "هناك عدد " : "There are "}
                    <span className="text-danger fw-bold">{(WishListCount)}</span>
                    {language === "ar" ? " من المنتجات في قائمتك." : " products in your wishlist."}
                </h6>
            </div>
            {wishList.length > 0 ? (
                <div className="row m-0 px-3 justify-content-center">
                    <div className="col-12 p-3 px-0 pt-0">
                        <div className="row align-items-center p-3 rounded-4 bg-most-used-40">
                            {["Product", "Price", "Actions"].map((label, idx) => (
                                <h6
                                    key={idx}
                                    className={`color-most-used p-0 m-0 col-${idx === 0 ? 5 : idx === 1 ? 3 : 4} fw-bold `}
                                >
                                    {language === "ar"
                                        ? ["المنتج", "السعر", "الأفعال"][idx]
                                        : label}
                                </h6>
                            ))}
                        </div>
                        {wishList.map((item, index) => (
                            <div key={index} className="row align-items-center border-bottom border-1 rounded-4 bg-most-used-10 py-2">
                                <div className="col-5 d-flex align-items-center">
                                    {item.product_image && (
                                        <img
                                            src={item.product_image}
                                            alt={item.product_id}
                                            loading="lazy"
                                            className="col-3"
                                        />
                                    )}
                                    {item.offer_image && (
                                        <img
                                            src={item.offer_image}
                                            alt={item.offer_id}
                                            loading="lazy"
                                            className="col-3"
                                        />
                                    )}
                                    <div className="col-9">
                                        <h6 className="color-most-used fw-bold">
                                            {item.product_id && item.product_title}
                                            {item.offer_id && item.offer_title}
                                        </h6>
                                        <Rating
                                            name="read-only"
                                            value={parseInt(item.product_rating) || parseInt(item.offer_rating) || 5}
                                            size="small"
                                            readOnly
                                        />
                                    </div>
                                </div>
                                <h6 className="color-most-used col-3">
                                    {item.product_price || item.offer_price}
                                </h6>
                                <div className="col-4 text-center">
                                    {item.product_id &&
                                        <Link to={`/product/${item.product_title}`}>
                                            <Button
                                                className="rounded-circle color-most-used mx-2"
                                                sx={{
                                                    width: '40px',
                                                    height: '40px',
                                                    minWidth: '0',
                                                    padding: 0,
                                                }}
                                            >
                                                <FaEye size={24} />
                                            </Button>
                                        </Link>
                                    }
                                    {item.offer_id &&
                                        <Link to={`/offer/${item.offer_id}`}>
                                            <Button
                                                className="rounded-circle color-most-used mx-2"
                                                sx={{
                                                    width: '40px',
                                                    height: '40px',
                                                    minWidth: '0',
                                                    padding: 0,
                                                }}
                                            >
                                                <FaEye size={24} />
                                            </Button>
                                        </Link>
                                    }
                                    <Button
                                        variant="contained"
                                        className="rounded-circle bg-danger text-light p-2"
                                        sx={{
                                            width: '40px',
                                            height: '40px',
                                            minWidth: '0',
                                            padding: 0,
                                        }}
                                        onClick={() => handleRemoveItem(item.id)}
                                    >
                                        <CiCircleRemove size={24} />
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            ) : (
                <EmptyWishListMessage language={language} />
            )}
        </div>
    );
}

function EmptyWishListMessage({ language }) {
    return (
        <div className="row justify-content-center">
            <div className="col-12 d-flex justify-content-center">
                <img src={emptyWishList} loading="lazy" alt="empty cart" className="col-2" />
            </div>
            <h4 className="text-center fw-bold color-most-used mt-1">
                {language === "ar" ? "قائمة الرغبات فارغة" : "Your WishList is currently empty"}
            </h4>
            <h6 className="text-center text-secondary">
                {language === "ar"
                    ? "الرجاء اختيار المنتجات التي ترغب في إضافتها"
                    : "Please choose the products you want to add"}
            </h6>
            <div className="col-12 d-flex justify-content-center mt-3">
                <Link to="/" className="text-decoration-none col-3">
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

export default PhoneWishList;
