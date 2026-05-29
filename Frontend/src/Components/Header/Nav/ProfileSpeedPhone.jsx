import { useContext } from 'react';
import { MyContext } from "../../../Context/Context";
import { Link, useNavigate } from 'react-router-dom';
import SpeedDial from '@mui/material/SpeedDial';
import SpeedDialAction from '@mui/material/SpeedDialAction';
import { FaRegHeart, FaLanguage } from 'react-icons/fa';
import { RiBillLine } from "react-icons/ri";
import { IoPersonSharp } from "react-icons/io5";
import { IoIosPerson, IoIosLogOut } from "react-icons/io";
import logo from '../../../assets/images/logo.webp';

export default function ProfileSpeedPhone() {
    const { language, setLanguage } = useContext(MyContext);
    const navigate = useNavigate();

    function toggleLang() {
        setLanguage(language === 'ar' ? 'en' : 'ar');
    }

    const actions = [
        { icon: <IoIosPerson />, name: language === 'ar' ? 'تعديل الملف الشخصي' : 'Edit Profile', to: '/edit-profile' },
        { icon: <FaRegHeart />, name: language === 'ar' ? 'قائمة الأمنيات' : 'Wish List', to: '/wish-list' },
        { icon: <RiBillLine />, name: language === 'ar' ? 'قائمة الطلبات' : 'Order List', to: '/order-list' },
        {
            icon: <FaLanguage />,
            name: language === 'ar' ? 'تغيير اللغة' : 'Language',
            onClick: toggleLang,
        },
    ];
    const handleLogout = async () => {
        const token = sessionStorage.getItem("token");
        const API_URL = `https://dash.watchizereg.com/api/logout?token=${token}`;

        try {
            const response = await fetch(API_URL, {
                method: "POST",
                headers: {
                    "Api-Code": "NbmFylY0vcwnhxUrm1udMgcX1MtPYb4QWXy1EKqVenm6uskufcXKeHh5W4TM5Iv0",
                },
            });

            if (response.ok) {
                // console.log("Logout successful");
                sessionStorage.clear();
                localStorage.clear();
                navigate("/");
                window.location.reload();

            } else {
                // console.error("Failed to log out:", response.statusText);
            }
        } catch (error) {
            // console.error("Logout error:", error);
            if (error === "Token has expired") {
                sessionStorage.clear();
                localStorage.clear();
                navigate("/");
                window.location.reload();
            }
        }
    };

    return (
        <>
            <div className="col-12 p-3 d-flex" style={{ position: 'relative', zIndex: 1000 }}>
                <Link to="/" style={{ textDecoration: "none" }}>
                    <img src={logo} alt="logo" className="logo" style={{ height: "50px", maxWidth: "150px", cursor: "pointer" }} />
                </Link>
            </div>
            <SpeedDial
                ariaLabel="Profile actions"
                sx={{
                    position: 'fixed',
                    right: 16,
                    top: 16,
                    zIndex: 1000,
                    '& .MuiFab-primary': {
                        backgroundColor: '#262626FF',
                        color: '#fff',
                    },
                    '& .MuiSpeedDialAction-fab': {
                        backgroundColor: '#262626FF',
                        color: '#fff',
                        '&:hover': {
                            backgroundColor: '#333333',
                        },
                    },
                    '& .MuiSpeedDialAction-staticTooltipLabel': {
                        backgroundColor: '#444',
                        color: '#fff',
                        whiteSpace: 'nowrap',
                        minWidth: '120px',
                        textAlign: 'center',
                        padding: '5px 10px',
                    },
                }}
                direction="down"
                icon={<IoPersonSharp />}
            >
                {actions.map((action) => (
                    <SpeedDialAction
                        key={action.name}
                        icon={
                            action.to ? (
                                <Link to={action.to} style={{ color: 'inherit', textDecoration: 'none' }}>
                                    {action.icon}
                                </Link>
                            ) : (
                                <span style={{ color: 'inherit', cursor: 'pointer' }} onClick={action.onClick}>
                                    {action.icon}
                                </span>
                            )
                        }
                        tooltipTitle={action.name}
                        tooltipOpen={true}
                    />
                ))}
                <SpeedDialAction
                    key="Log Out"
                    icon={<IoIosLogOut />}
                    tooltipTitle="Log Out"
                    onClick={handleLogout}
                    tooltipOpen={true}
                />
            </SpeedDial>
        </>
    );
}
