import './Footer.css';
import { FaShippingFast, FaPhoneVolume, FaUndoAlt, FaTag, FaFacebookF, FaInstagram } from "react-icons/fa";
import { useContext } from 'react';
import { MyContext } from '../../Context/Context';
import { Link, useNavigate } from 'react-router-dom';
import { FaRegHeart } from "react-icons/fa";
import { RiBillLine } from "react-icons/ri";
import { IoIosPerson } from "react-icons/io";

function Footer() {
    const { language, tables, setFilters } = useContext(MyContext);
    const navigate = useNavigate();
    const half = Math.ceil((tables.brands?.length || 0) / 2);
    const firstHalfBrands = tables.brands?.slice(0, half) || [];
    const secondHalfBrands = tables.brands?.slice(half) || [];

    const profileActions = [
        { icon: <IoIosPerson />, name: language === "ar" ? "تعديل الملف الشخصي" : "Edit Profile", to: "/edit-profile" },
        { icon: <FaRegHeart />, name: language === "ar" ? "قائمة الامنيات" : "Wish List", to: "/wish-list" },
        { icon: <RiBillLine />, name: language === "ar" ? "قائمة الاوردرات" : "Order List", to: "/order-list" },
    ];

    const handleLogout = async () => {
        sessionStorage.clear();
        localStorage.clear();
        navigate("/");
        window.location.reload();
    };

    return (
        <footer className='footer'>
            {/* Top Section with Features */}
            <div className='top-features container py-4'>
                <div className='row py-4 border-bottom border-1 text-center'>
                    <div className={`col-6 col-md-3 ${language === 'ar' ? 'border-start' : 'border-end'}  border-1 feature-item`}>
                        <p><FaShippingFast className="mx-2" style={{ fontSize: "1.5rem" }} />{language === 'ar' ? 'شحن سريع ' : 'Fast Shipping'}</p>
                    </div>
                    <div className={`col-6 col-md-3 ${language === 'ar' ? 'border-start' : 'border-end'}  border-1 feature-item`}>
                        <p><FaPhoneVolume className="mx-2" style={{ fontSize: "1.5rem" }} />{language === 'ar' ? 'دعم فني 24/7' : '24/7 Customer Support'}</p>
                    </div>
                    <div className={`col-6 col-md-3 ${language === 'ar' ? 'border-start' : 'border-end'}  border-1 feature-item`}>
                        <p><FaUndoAlt className="mx-2" style={{ fontSize: "1.5rem" }} />{language === 'ar' ? 'سياسة إرجاع سهلة' : 'Easy Return Policy'}</p>
                    </div>
                    <div className='col-6 col-md-3 feature-item'>
                        <p><FaTag className="mx-2" style={{ fontSize: "1.5rem" }} />{language === 'ar' ? 'أفضل العروض والأسعار' : 'Best Deals & Prices'}</p>
                    </div>
                </div>
            </div>

            {/* Footer Categories */}
            <div className='footer-categories container py-5'>
                <div className='row m-0'>
                    <div className='col-6 col-lg-3 mb-4'>
                        <h6 className='category-title'>{language === 'ar' ? 'الفئات الفرعية' : 'SubTypes'}</h6>
                        <ul className='list-unstyled'>
                            {tables.subTypes && tables.subTypes.map((subtype) => (
                                <li key={subtype.id}>
                                    <Link to={`/subtypes/${subtype.sub_type_name}`} onClick={() => setFilters({ categories: [], brands: [], subTypes: [subtype.id], price: [0, 6000] })}>
                                        {subtype.translations.find(t => t.locale === language)?.sub_type_name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div className='col-6 col-lg-3 mb-4'>
                        <h6 className='category-title'>{language === "ar" ? "العلامات التجارية" : "Brands"}</h6>
                        <ul className='list-unstyled'>
                            {firstHalfBrands.map((brand) => (
                                <li key={brand.id}>
                                    <Link to={`/brand/${brand.brand_name}`} onClick={() => setFilters({ categories: [], brands: [brand.id], subTypes: [], price: [0, 6000] })}>
                                        {brand.translations.find(t => t.locale === language)?.brand_name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div className='col-6 col-lg-3 mb-4'>
                        <h6 className='category-title'>{language === "ar" ? "العلامات التجارية" : "Brands"}</h6>
                        <ul className='list-unstyled'>
                            {secondHalfBrands.map((brand) => (
                                <li key={brand.id}>
                                    <Link to={`/brand/${brand.brand_name}`} onClick={() => setFilters({ categories: [], brands: [brand.id], subTypes: [], price: [0, 6000] })}>
                                        {brand.translations.find(t => t.locale === language)?.brand_name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div className='col-6 col-lg-3 mb-4'>
                        <h6 className='category-title'>{language === "ar" ? "افعال سريعة" : "Fast Actions"}</h6>
                        <ul className='list-unstyled'>
                            {profileActions.map((action) => (
                                <li key={action.to}>
                                    <Link to={action.to}>{action.name}</Link>
                                </li>
                            ))}
                            <li>
                                <Link to={'/cart'}>{language === "ar" ? "سلة المشتريات" : "Cart"}</Link>
                            </li>
                            <li>
                                <Link to={'/blogs'}>{language === "ar" ? "المدونات" : "Blogs"}</Link>
                            </li>
                            <li>
                                <button className='btn btn-link  p-0' onClick={handleLogout}>{language === "ar" ? "تسجيل الخروج" : "Log Out"}</button>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            {/* Footer Bottom */}
            <div className='footer-bottom container py-3 text-center'>
                <div className='row align-items-center'>
                    <div className='col-6'>
                        <p className={`text-${language === 'ar' ? 'end' : 'start'}`}>
                            &copy; {new Date().getFullYear()} {language === 'ar' ? 'جميع الحقوق محفوظة لمايكل خلف' : 'Watchizer All Rights Reserved For Michael Khalaf'}
                        </p>
                    </div>
                    <div className={`col-6 d-flex ${language === 'ar' ? 'justify-content-start' : 'justify-content-end'} social-buttons`}>
                        <a href="https://www.facebook.com/profile.php?id=100076267296916" target="_blank" rel="noopener noreferrer" aria-label="Facebook" className='mx-2'>
                            <FaFacebookF style={{ height: '20px', width: '20px' }} />
                        </a>
                        <a href="https://www.instagram.com/watchizer_eg/" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
                            <FaInstagram style={{ height: '20px', width: '20px' }} />
                        </a>
                    </div>
                </div>
            </div>
        </footer>
    );
}

export default Footer;
