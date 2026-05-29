import { useContext, useState } from 'react';
import PropTypes from 'prop-types';
import { Link } from 'react-router-dom';
import Slider from 'react-slick';
import { Rating } from '@mui/material';
import {
    IoIosArrowForward,
    IoIosArrowBack,
    IoIosArrowDropleftCircle,
    IoIosArrowDroprightCircle,
} from 'react-icons/io';
import { FaRegHeart } from 'react-icons/fa';
import { SlSizeFullscreen } from 'react-icons/sl';
import { MyContext } from '../../Context/Context';
import './Product.css';
import OfferModel from './OfferModel';

function NextArrow({ onClick }) {
    return (
        <IoIosArrowDroprightCircle
            style={{
                fontSize: '40px',
                color: '#26262696',
                position: 'absolute',
                top: '50%',
                transform: 'translateY(-50%)',
                right: '10px',
                zIndex: 10,
                cursor: 'pointer',
            }}
            onClick={onClick}
        />
    );
}

function PrevArrow({ onClick }) {
    return (
        <IoIosArrowDropleftCircle
            style={{
                fontSize: '40px',
                color: '#26262696',
                position: 'absolute',
                top: '50%',
                transform: 'translateY(-50%)',
                left: '10px',
                zIndex: 10,
                cursor: 'pointer',
            }}
            onClick={onClick}
        />
    );
}

function OfferSlider({ text, products, to }) {
    const { language, windowWidth, handleAddTowishlist } = useContext(MyContext);
    const [selectedProduct, setSelectedProduct] = useState(null);
    const [isModalOpen, setIsModalOpen] = useState(false);

    const handleProductClick = (product) => {
        setSelectedProduct(product);

        setIsModalOpen(true);
    };

    const handleModalClose = () => {
        setIsModalOpen(false);
        setSelectedProduct(null);
    };

    const sliderSettings = {
        dots: false,
        infinite: false,
        speed: 300,
        slidesToShow: 4,
        slidesToScroll: 2,
        nextArrow: <NextArrow />,
        prevArrow: <PrevArrow />,
        rtl: language === 'ar',
        responsive: [
            { breakpoint: 1024, settings: { slidesToShow: 3, slidesToScroll: 2 } },
            { breakpoint: 768, settings: { slidesToShow: 2, slidesToScroll: 1 } },
            { breakpoint: 480, settings: { slidesToShow: 2, slidesToScroll: 1 } },
        ],
    };
    return (
        <>
            <div className="col-12 d-flex px-3 info">
                <div className="col-md-10 col-8">
                    <h4 className="color-most-used fw-bold">
                        {language === 'ar' ? text.title.ar : text.title.en}
                    </h4>
                    <p className="text-secondary fs-large" style={{ fontSize: "small" }}>
                        {language === 'ar' ? text.description.ar : text.description.en}
                    </p>
                </div>
                <div className="col-md-2 col-4">
                    <Link className="color-most-used rounded-4 p-2 border border-1" to={to}>
                        {language === 'ar' ? 'مشاهدة المزيد' : 'View More'}
                        {language === 'ar' ? <IoIosArrowBack className="me-2" /> : <IoIosArrowForward className="ms-2" />}
                    </Link>
                </div>
            </div>

            <div className="row product-slider pb-5">
                <Slider {...sliderSettings}>
                    {products.map((product) => (
                        <div key={product.id} className="p-2" style={{ height: '100%' }}>
                            <div className="card product-card border-0 rounded-3 shadow-sm d-flex flex-column position-relative">
                                <div className="action-menu position-absolute">
                                    {windowWidth >= 768 ?
                                        <button
                                            className="btn btn-dark rounded-circle"
                                            onClick={() => handleProductClick(product)}
                                        >
                                            <SlSizeFullscreen />
                                        </button>
                                        :
                                        <Link
                                            to={`/offer/${product.id}`}
                                            className="btn btn-dark rounded-circle"
                                        >
                                            <SlSizeFullscreen />
                                        </Link>
                                    }
                                    <button
                                        className="btn mt-2 btn-danger rounded-circle"
                                        onClick={() => handleAddTowishlist(product.id, "o")}>
                                        <FaRegHeart />
                                    </button>
                                </div>
                                <div className="product-img-container">
                                    <Link to={`/offer/${product.id}`}>
                                        <img
                                            src={product.image || "/placeholder.png"}
                                            alt={product.offer_name_en || "Product"}
                                            className="img-fluid rounded-top"
                                            loading="lazy"
                                        />
                                    </Link>
                                </div>
                                <div className="card-body d-flex flex-column justify-content-between p-3">
                                    <h6 className={`card-title ${language === 'ar' ? 'text-end' : ''} fs-large fw-bold mb-2`} style={{ fontSize: 'small' }}>{language === "ar" ? product.product_name_ar : product.offer_name_en}</h6>
                                    <p className={`card-text ${language === 'ar' ? 'text-end' : ''}  text-secondary mb-3`} style={{ fontSize: '0.9rem' }}>
                                        {language === "ar" ?
                                            product?.short_description_ar?.length > 100
                                                ? `${product?.short_description_ar?.slice(0, 100)}...`
                                                : product?.short_description_ar
                                            :
                                            product?.short_description_en?.length > 100
                                                ? `${product?.short_description_en?.slice(0, 100)}...`
                                                : product?.short_description_en
                                        }
                                    </p>
                                    <div className="d-flex justify-content-center align-items-center mb-2">
                                        <span className="color-most-used fw-bold me-2 fs-large" style={{ fontSize: 'small' }}>
                                            {Math.round(product.sale_price_after_discount)} {language === 'ar' ? 'ج.م' : 'EGP'}
                                        </span>
                                        <span className="text-muted text-decoration-line-through fs-large" style={{ fontSize: 'small' }}>
                                            {Math.round(product.selling_price)} {language === 'ar' ? 'ج.م' : 'EGP'}
                                        </span>
                                    </div>
                                    <div className="d-md-flex  justify-content-between align-items-center">
                                        <div className='col-md-5 col-12 p-1'>
                                            <span className={`badge ${parseInt(product.stock) > 0 ? 'bg-success' : 'bg-danger'} col-12`}>
                                                {language === 'ar' ? (parseInt(product.stock) > 0 ? 'متوفر' : 'غير متوفر') : (parseInt(product.stock) > 0 ? 'In Stock' : 'Out of Stock')}
                                            </span>
                                        </div>
                                        <div className="d-flex col-md-7 p-1 justify-content-center col-12 align-items-center">
                                            <Rating name="read-only" className={`${windowWidth <= 768 ? "col-12" : ""}`} value={Math.round(product.average_rate === null ? 5 : product.average_rate)} size="small" readOnly />
                                            <span className={` mx-1 ${windowWidth <= 768 ? "d-none" : ""}`}>({Math.round(product.average_rate === null ? 5 : product.average_rate)})</span>
                                        </div>
                                    </div>
                                    <Link className="btn btn-outline-dark rounded-4 mt-2" to={`/offer/${product.id}`} disabled={product.stock <= 0}>
                                        {language === "ar" ? "أضف إلى السلة" : "Add to Cart"}
                                    </Link>
                                </div>
                            </div>
                        </div>
                    ))}
                </Slider>
            </div>

            {selectedProduct && (
                <OfferModel
                    open={isModalOpen}
                    onClose={handleModalClose}
                    product={selectedProduct}
                    language={language}
                />
            )}
        </>
    );
}

OfferSlider.propTypes = {
    text: PropTypes.shape({
        title: PropTypes.shape({
            en: PropTypes.string.isRequired,
            ar: PropTypes.string.isRequired,
        }).isRequired,
        description: PropTypes.shape({
            en: PropTypes.string.isRequired,
            ar: PropTypes.string.isRequired,
        }).isRequired,
    }).isRequired,
    products: PropTypes.arrayOf(
        PropTypes.shape({
            id: PropTypes.number.isRequired,
            main_product_id: PropTypes.number.isRequired,
            category_type_id: PropTypes.number.isRequired,
            gift_product_ids: PropTypes.arrayOf(PropTypes.number),
            selling_price: PropTypes.number.isRequired,
            sale_price_after_discount: PropTypes.number.isRequired,
            stock: PropTypes.number.isRequired,
            image: PropTypes.string.isRequired,
            average_rate: PropTypes.number,
            created_at: PropTypes.string.isRequired,
            updated_at: PropTypes.string.isRequired,
            short_description_en: PropTypes.string,
            short_description_ar: PropTypes.string,
            long_description_en: PropTypes.string,
            long_description_ar: PropTypes.string,
            in_season: PropTypes.string,
            offer_name_en: PropTypes.string.isRequired,
            offer_name_ar: PropTypes.string.isRequired,
            offer_rating: PropTypes.arrayOf(
                PropTypes.shape({
                    id: PropTypes.number.isRequired,
                    user_id: PropTypes.number.isRequired,
                    offer_id: PropTypes.number.isRequired,
                    rating: PropTypes.number.isRequired,
                    comment: PropTypes.string,
                    created_at: PropTypes.string.isRequired,
                    updated_at: PropTypes.string.isRequired,
                })
            ),
        })
    ).isRequired,
};



export default OfferSlider;
