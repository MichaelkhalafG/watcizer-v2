import { IoIosSearch } from "react-icons/io";
import { Button } from "@mui/material";
import { useContext, useState, useEffect } from "react";
import { MyContext } from "../../../Context/Context";
import { useNavigate } from "react-router-dom";

function SearchBox() {
    const { language, products, setFilteredProducts } = useContext(MyContext);
    const [searchTerm, setSearchTerm] = useState("");
    const navigate = useNavigate();

    const handleSearch = () => {
        if (searchTerm.trim() !== "") {
            const filtered = products.filter((product) =>
                product.product_title.toLowerCase().includes(searchTerm.toLowerCase()) ||
                product.short_description.toLowerCase().includes(searchTerm.toLowerCase()) ||
                product.brand.toLowerCase().includes(searchTerm.toLowerCase())
            );
            setFilteredProducts(filtered);
            navigate(`/listingsearch?query=${encodeURIComponent(searchTerm)}`);
        }
    };

    useEffect(() => {
        const delayDebounce = setTimeout(() => {
            if (searchTerm.trim() === "") {
                setFilteredProducts(products);
            } else {
                const filtered = products.filter((product) =>
                    product.product_title.toLowerCase().includes(searchTerm.toLowerCase()) ||
                    product.short_description.toLowerCase().includes(searchTerm.toLowerCase()) ||
                    product.brand.toLowerCase().includes(searchTerm.toLowerCase()) ||
                    product.search_keywords?.toLowerCase().includes(searchTerm.toLowerCase())
                );
                setFilteredProducts(filtered);
            }
        }, 300);

        return () => clearTimeout(delayDebounce);
    }, [searchTerm, products, setFilteredProducts]);

    return (
        <div className="search-container rounded-3 ms-3 me-3 p-2 px-4" style={{ position: "relative", width: "60%" }}>
            <div className="header-search rounded-3 p-2 px-4 border border-2"
                style={{ height: "60px", background: "#f3f4f7", position: "relative" }}>
                <input
                    type="text"
                    value={searchTerm}
                    onChange={(e) => setSearchTerm(e.target.value)}
                    onKeyDown={(e) => e.key === "Enter" && handleSearch()}
                    placeholder={language === "ar" ? "البحث عن المنتجات" : "Search for products..."}
                    className="rounded-3 border border-0"
                    style={{
                        background: "transparent",
                        outline: "none",
                        fontSize: "18px",
                        color: "rgba(0,0,0,0.8)",
                        height: "40px",
                        width: "100%",
                    }}
                />
                <Button
                    className="p-0 border searchbtn border-0 text-black"
                    type="submit"
                    onClick={handleSearch}
                    title='search'
                    aria-hidden="true"
                    style={{
                        background: "transparent",
                        position: "absolute",
                        height: "40px",
                        width: "40px",
                        borderRadius: "50%",
                        minWidth: "40px",
                        [language === "ar" ? "left" : "right"]: "10px",
                    }}
                >
                    <IoIosSearch />
                </Button>
            </div>
        </div>
    );
}

export default SearchBox;
