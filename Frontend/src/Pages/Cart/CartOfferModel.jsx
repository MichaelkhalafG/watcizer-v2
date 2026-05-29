import { useState, useEffect, useContext } from 'react';
import PropTypes from 'prop-types';
import { Modal, Box, Button, Rating } from '@mui/material';
import { MdClose } from 'react-icons/md';
import InnerImageZoom from 'react-inner-image-zoom';
import defimg from '../../assets/images/1.webp'
import 'react-inner-image-zoom/lib/InnerImageZoom/styles.css';
import { MyContext } from '../../Context/Context';
import { Link } from 'react-router-dom';

function CartOfferModel({ open, onClose, product, language, quantity }) {
    const { tables, products, handleAddTowishlist } = useContext(MyContext)
    const [selectedImage, setSelectedImage] = useState('');
    const [stock, setstock] = useState();

    useEffect(() => {
        if (product) {
            setSelectedImage(product.image || product.images?.[0] || "");
            setstock(parseInt(product.stock));
        }

    }, [product]);

    const renderDetailquantity = (labelEn, labelAr, value) => (
        <div className="col-6 d-flex align-items-center">
            <p className="fw-bold m-0 p-1  border border-dark border-1 rounded-3 color-most-used" style={{ fontSize: 'medium' }}>
                <span className={`${language === 'ar' ? 'ms-2' : 'me-2'}`}>
                    {language === 'ar' ? `${labelAr} :` : `${labelEn} :`}
                </span>
                {value || '-'}
            </p>
        </div>
    );

    const renderDetail = (labelEn, labelAr, value, fs = '1rem', col = 'col-4') => (
        <div className={`${col}`}>
            <p className="fw-bold text-secondary" style={{ fontSize: fs }}>
                <span className={`${language === "ar" ? "ms-2" : "me-2"}`}>
                    {language === "ar" ? `${labelAr} :` : `${labelEn} :`}
                </span>
                {value || "-"}
            </p>
        </div>
    );

    const render_product_ids = (labelEn, ids, labelAr, col = 'col-3') => (
        ids.map((id, key) => (
            <div className={`${col} p-1`} key={key}>
                <Link to={`/product/${id}`}>
                    <img className='col-12 border border-1 rounded-3' src={products.find(p => p.id === id)?.image} loading='lazy' alt={`product ${id}`} />
                </Link>
            </div >
        ))
    );

    const getTranslatedName = (translations, locale, fallback) => {
        return translations.find((t) => t.locale === locale)?.[fallback] || 'Unknown';
    };

    if (!product) return null;

    return (
        <Modal open={open} onClose={onClose}>
            <Box
                sx={{
                    position: 'absolute',
                    top: '50%',
                    left: language === 'ar' ? 'unset' : '50%',
                    right: language === 'ar' ? '50%' : 'unset',
                    transform: language === 'ar' ? 'translate(50%, -50%)' : 'translate(-50%, -50%)',
                    width: '80%',
                    maxWidth: '900px',
                    bgcolor: 'background.paper',
                    boxShadow: 24,
                    p: 4,
                    borderRadius: '10px',
                }}
                className={`p-5 ${language === 'ar' ? 'rtl' : 'ltr'}`}
                style={{
                    position: 'relative',
                    direction: language === 'ar' ? 'rtl' : 'ltr',
                }}
            >
                <div className="row border-bottom border-2 product-header mb-3">
                    <div className="col-12">
                        <h4 className="fw-bold">{language === "ar" ? product.offer_name_ar : product.offer_name_en}</h4>
                    </div>
                    {renderDetail('Category Type', 'النوع', getTranslatedName(tables.categoryTypes.find((t) => t.id === product.category_type_id)?.translations || [], language, 'category_type_name'))}
                    {renderDetail('Price', 'السعر', product.price)}
                    <Rating name="read-only" value={Math.round(product.average_rate === null ? 5 : product.average_rate)} size="small" readOnly />
                </div>
                <div className="row product-details">
                    <div className="col-md-5 product-images">
                        <div className="selected-image mb-3 d-flex justify-content-center">
                            {selectedImage && (
                                <InnerImageZoom
                                    src={selectedImage}
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
                    <div className="col-7 row product-info">
                        {/* <h5 className="">{language === 'ar' ? 'التفاصيل' : 'Details'}</h5>
                        <p className="text-secondary " style={{ fontSize: 'small' }}>
                            {product.long_description}
                        </p> */}
                        <div className='col-12 p-1'>
                            <p className="fw-bold text-secondary" style={{ fontSize: "large" }}>
                                {language === "ar" ? `المنتج الاساسي :` : `Main Product :`}
                            </p>
                            <Link to={`/product/${product.main_product_id}`} className='col-4' style={{
                                fontSize: "small"
                            }}>
                                <img className='col-4 border border-1 rounded-3' src={products.find(p => p.id === product.main_product_id)?.image} loading='lazy' alt={`product ${product.main_product_id}`} />
                            </Link>
                        </div>
                        <div className='row p-3'>
                            <p className="fw-bold text-secondary" style={{ fontSize: "large" }}>
                                {language === "ar" ? `منتجات العرض :` : `Offer Products :`}
                            </p>
                            {render_product_ids('Gift Products', product.gift_product_ids, 'منتجات العرض')}
                        </div>
                        {quantity && renderDetailquantity('Quantity', 'الكمية', quantity)}
                        <div className="col-6 d-flex align-items-center">
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
                        <div className="mt-3 row action-buttons">
                            <div className='col-6 p-1'>
                                <button
                                    className="btn btn-outline-danger col-12 "
                                    onClick={() => handleAddTowishlist(product.id, "o")}
                                >
                                    {language === "ar" ? "أضف إلى قائمة الرغبات" : "Add to Wish List"}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <Button
                    className="close"
                    style={{
                        position: 'absolute',
                        top: '10px',
                        right: language === 'ar' ? 'unset' : '10px',
                        left: language === 'ar' ? '10px' : 'unset',
                        color: '#555',
                    }}
                    onClick={onClose}
                >
                    <MdClose />
                </Button>
            </Box>
        </Modal>
    );
}

CartOfferModel.propTypes = {
    open: PropTypes.bool.isRequired,
    onClose: PropTypes.func.isRequired,
    product: PropTypes.shape({
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
    }).isRequired,
    language: PropTypes.string.isRequired,
};

export default CartOfferModel;
