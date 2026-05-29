import { useContext } from 'react';
import { Link } from 'react-router-dom';
import { IoBagOutline } from 'react-icons/io5';
import { AiOutlineHome } from "react-icons/ai";
import { MdOutlineLocalOffer, MdOutlineWatch } from "react-icons/md";
import { IoSearch } from "react-icons/io5";
import './PhoneNavBar.css';
import { MyContext } from '../../../Context/Context';

function PhoneNavBar() {
    const { tables, setFilters, setCurrentPage, language, productsCount } = useContext(MyContext);

    const handleCategoryClick = (categoryName) => {
        const category = tables.categoryTypes.find(
            (category) => category.category_type_name === categoryName
        );

        if (category) {
            setFilters({
                categories: [category.id],
                brands: [],
                subTypes: [],
                price: [0, 6000],
            });
            setCurrentPage(1);
        }
    };

    return (
        <div className="phone-nav-bar d-md-none d-block bg-light position-relative position-fixed text-light p-3 px-0 pb-0">
            <div className="rounded-circle align-items-center justify-content-center d-flex flex-column text-center bg-dark position-absolute"
                style={{ top: "-8px", left: "50%", transform: "translate(-50%, -50%)", height: "50px", width: "50px" }}>

                <Link to="/cart" className="text-light d-flex align-items-center justify-content-center position-relative p-2">
                    <IoBagOutline size={24} />

                    {/* Product count badge */}
                    {productsCount > 0 && (
                        <span className="position-absolute start-100 translate-middle badge rounded-pill"
                            style={{ background: "#ea2b0f", top: "5px", fontSize: "12px", minWidth: "20px", padding: "4px 6px" }}>
                            {productsCount}
                        </span>
                    )}
                </Link>
            </div>

            <div className="d-flex col-12 shadow-1 p-0 pb-2 m-0">
                <Link to="/" className="col-3 text-decoration-none border-end text-center color-most-used px-3">
                    <AiOutlineHome style={{ fontSize: "20px" }} />
                </Link>
                <Link
                    to="/category/Watches"
                    className="col-3 text-decoration-none border-end text-center color-most-used px-3"
                    onClick={() => handleCategoryClick("Watches")}
                >
                    <MdOutlineWatch style={{ fontSize: "20px" }} />
                </Link>
                <Link
                    to="/Search"
                    className="col-3 text-decoration-none fw-bold border-end text-center color-most-used px-3"
                >
                    <IoSearch style={{ fontSize: "20px" }} />
                </Link>
                <Link to="/offers" className="col-3 text-decoration-none border-end text-center color-most-used px-3" onClick={() => setCurrentPage(1)}>
                    <MdOutlineLocalOffer style={{ fontSize: "20px" }} />
                </Link>
            </div>
            <div className='footer-bottom bg-dark container py-1 text-center'>
                <div className='row align-items-center'>
                    <div className='col-12'>
                        <p className={`text-center text-light`} style={{ fontSize: "x-small" }}>
                            &copy; {new Date().getFullYear()} {language === 'ar' ? 'جميع الحقوق محفوظة لمايكل خلف' : 'Watchizer All Rights Reserved For Michael Khalaf'}
                        </p>
                    </div>
                </div>
            </div>
        </div >
    );
}

export default PhoneNavBar;
