import { IoIosSearch } from "react-icons/io";
import { IoMdClose } from "react-icons/io";
import { Button, IconButton, Snackbar, Alert, Pagination } from "@mui/material";
import { useContext, useState, useEffect, useRef, Suspense } from "react";
import { LazyLoadImage } from "react-lazy-load-image-component";
import "react-lazy-load-image-component/src/effects/blur.css";
import { MyContext } from "../../Context/Context";
import { Link, useNavigate } from "react-router-dom";
import axios from "axios";
import { FaRegHeart } from "react-icons/fa";
import { SlSizeFullscreen } from "react-icons/sl";
import './Search.css';

function SearchPageForPhone() {
    const { language, products, handleAddTowishlist, currentPage, setCurrentPage, fetchCart, offers, setCart, user_id, Loader } = useContext(MyContext);
    const [searchTerm, setSearchTerm] = useState("");
    const [filteredResults, setFilteredResults] = useState([]);
    const [alertMessage, setAlertMessage] = useState("");
    const [alertType, setAlertType] = useState("info");
    const [openAlert, setOpenAlert] = useState(false);
    const containerRef = useRef(null);
    const navigate = useNavigate();
    const itemsPerPage = 10;
    const totalPages = Math.ceil(filteredResults.length / itemsPerPage);

    const paginatedResults = filteredResults.slice(
        (currentPage - 1) * itemsPerPage,
        currentPage * itemsPerPage
    );
    const handlePageChange = (e, value) => {
        if (containerRef.current) {
            containerRef.current.scrollTo({ top: 0, behavior: "smooth" });
        }
        setCurrentPage(value);
    };
    const handleClose = () => navigate(-1);

    useEffect(() => {
        const delayDebounce = setTimeout(() => {
            setFilteredResults(
                searchTerm.trim() === "" ? [] :
                    products.filter((product) =>
                        product.product_title.toLowerCase().includes(searchTerm.toLowerCase()) ||
                        product.short_description.toLowerCase().includes(searchTerm.toLowerCase()) ||
                        product.brand.toLowerCase().includes(searchTerm.toLowerCase())
                    )
            );
            setCurrentPage(1);
        }, 300);
        return () => clearTimeout(delayDebounce);
    }, [searchTerm, products, setCurrentPage]);

    const handleAddToCart = (product, type_stock) => {
        if (!user_id) {
            setAlertMessage(language === "ar" ? "يجب تسجيل الدخول أولاً!" : "You must login first!");
            setAlertType("warning");
            setOpenAlert(true);
            return;
        }

        const piecePrice = parseInt(product.sale_price_after_discount, 10);
        const totalPrice = piecePrice * 1;

        if (isNaN(totalPrice) || totalPrice <= 0) {
            setAlertMessage(language === "ar" ? "حدث خطأ في حساب السعر." : "Error calculating total price.");
            setAlertType("error");
            setOpenAlert(true);
            return;
        }

        axios.post("https://dash.watchizereg.com/api/add_to_cart", {
            user_id,
            product_id: product.id,
            quantity: 1,
            piece_price: piecePrice,
            type_stock,
            total_price: totalPrice,
        }, {
            headers: { "Api-Code": "NbmFylY0vcwnhxUrm1udMgcX1MtPYb4QWXy1EKqVenm6uskufcXKeHh5W4TM5Iv0" }
        })
            .then(() => {
                setAlertMessage(language === "ar" ? "تمت الإضافة إلى السلة!" : "Added to the cart!");
                setAlertType("success");
                setOpenAlert(true);
                fetchCart(user_id, products, offers, language, setCart);
            })
            .catch(() => {
                setAlertMessage(language === "ar" ? "خطأ أثناء الإضافة." : "Error adding to cart.");
                setAlertType("error");
                setOpenAlert(true);
            });
    };

    return (
        <div ref={containerRef} className="search-overlay position-fixed bg-white d-flex flex-column p-3" style={{ zIndex: 1050, width: "100vw" }}>
            <Snackbar open={openAlert} autoHideDuration={3000} onClose={() => setOpenAlert(false)}>
                <Alert severity={alertType} onClose={() => setOpenAlert(false)}>{alertMessage}</Alert>
            </Snackbar>
            <div className="d-flex align-items-center justify-content-between mb-3">
                <h5>{language === "ar" ? "بحث" : "Search"}</h5>
                <IconButton onClick={handleClose}><IoMdClose /></IconButton>
            </div>
            <div className="search-box p-2 px-4 border rounded-3 d-flex align-items-center" style={{ background: "#f3f4f7" }}>
                <input
                    type="text"
                    value={searchTerm}
                    onChange={(e) => setSearchTerm(e.target.value)}
                    placeholder={language === "ar" ? "البحث عن المنتجات" : "Search for products..."}
                    className="border-0 flex-grow-1"
                    style={{ background: "transparent", outline: "none", fontSize: "18px" }}
                />
                <Button className="p-0 border-0 text-black" style={{ background: "transparent" }}>
                    <IoIosSearch />
                </Button>
            </div>
            <div className="col-12 py-2">
                <div className="row">
                    {paginatedResults.map((product) => (
                        <div key={product.id} className="p-2 col-6">
                            <div className="card border-0 rounded-3 shadow-sm position-relative">
                                <div className="action-menu position-absolute">
                                    <Link to={`/product/${product.product_title}`} className="btn btn-dark rounded-circle">
                                        <SlSizeFullscreen />
                                    </Link>
                                    <button className="btn mt-2 btn-danger rounded-circle" onClick={() => handleAddTowishlist(product.id, "p")}>
                                        <FaRegHeart />
                                    </button>
                                </div>
                                <Link to={`/product/${product.product_title}`}>
                                    <Suspense fallback={<Loader />}>
                                        <LazyLoadImage src={product.image || "/placeholder.png"} effect="blur" className="img-fluid rounded-top" />
                                    </Suspense>
                                </Link>
                                <div className="card-body p-3">
                                    <h6 className={`card-title ${language === 'ar' ? 'text-end' : ''} fs-large fw-bold mb-2`} style={{ fontSize: 'small' }}>
                                        {product.product_title.length > 30 ? (
                                            <>
                                                {product.product_title.slice(0, 30)}...
                                            </>
                                        ) : product.product_title.length <= 20 ? (
                                            <>
                                                {product.product_title}
                                                <br />
                                                <br />
                                            </>
                                        ) : (
                                            product.product_title
                                        )}
                                    </h6>
                                    <p className={`card-text ${language === 'ar' ? 'text-end' : ''}  text-secondary mb-3`} style={{ fontSize: '0.9rem' }}>
                                        {product.short_description.length > 100 ? (
                                            <>
                                                {product.short_description.slice(0, 100)}...
                                            </>
                                        ) : product.short_description.length <= 50 ? (
                                            <>
                                                {product.short_description}
                                                <br />
                                                <br />
                                            </>
                                        ) : (
                                            product.short_description
                                        )}
                                    </p>
                                    <div className="d-flex justify-content-center align-items-center mb-2">
                                        <span className="color-most-used fw-bold me-2 fs-large" style={{ fontSize: 'small' }}>
                                            {Math.round(product.sale_price_after_discount)} {language === 'ar' ? 'ج.م' : 'EGP'}
                                        </span>
                                        <span className="text-muted text-decoration-line-through fs-large" style={{ fontSize: 'small' }}>
                                            {Math.round(product.selling_price)} {language === 'ar' ? 'ج.م' : 'EGP'}
                                        </span>
                                    </div>

                                    <div className="row justify-content-between align-items-center">
                                        <div className='col-12 p-1'>
                                            <span className={`badge ${parseInt(product.stock) > 0 ? 'bg-black' : parseInt(product.market_stock) > 0 ? "bg-success" : 'bg-danger'} col-12`}>
                                                {language === 'ar' ? (parseInt(product.stock) > 0 ? 'اكسبريس' : parseInt(product.market_stock) > 0 ? "ماركت" : 'غير متوفر')
                                                    : (parseInt(product.stock) > 0 ? 'Express' : parseInt(product.market_stock) > 0 ? "Market Place" : 'Out of Stock')}
                                            </span>
                                        </div>
                                        {/* <div className="col-12 p-1 justify-content-center col-12 align-items-center">
                                                                <Rating name="read-only" className={`${windowWidth <= 768 ? "col-12" : ""}`} value={Math.round(product.rating === null ? 5 : product.rating)} size="small" readOnly />
                                                                <span className={` mx-1 ${windowWidth <= 768 ? "d-none" : ""}`}>({Math.round(product.rating === null ? 5 : product.rating)})</span>
                                                            </div> */}
                                    </div>
                                    {user_id && user_id !== null ?
                                        <Link onClick={() => handleAddToCart(product, (parseInt(product.stock) > 0 ? 'Express' : "Market"))}
                                            className="btn btn-outline-dark rounded-4 mt-2"
                                            disabled={parseInt(product.stock) <= 0}
                                        >
                                            {language === 'ar' ? 'أضف إلى السلة' : 'Add to Cart'}
                                        </Link>
                                        :
                                        <Link to={`/login`}
                                            className="btn btn-outline-dark rounded-4 mt-2"
                                            disabled={(parseInt(product.stock) <= 0 || parseInt(product.market_stock) <= 0)}
                                        >
                                            {language === 'ar' ? 'أضف إلى السلة' : 'Add to Cart'}
                                        </Link>
                                    }
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
                <Pagination
                    className={`${totalPages <= 1 ? 'd-none' : ''}`}
                    count={totalPages}
                    page={currentPage}
                    onChange={handlePageChange}
                    color="primary"
                />
            </div>
        </div>
    );
}

export default SearchPageForPhone;
