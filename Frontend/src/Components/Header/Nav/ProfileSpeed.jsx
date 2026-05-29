import Box from "@mui/material/Box";
import { Link, useNavigate } from "react-router-dom";
import SpeedDial from "@mui/material/SpeedDial";
import SpeedDialAction from "@mui/material/SpeedDialAction";
import { FaRegHeart } from "react-icons/fa";
import { RiBillLine } from "react-icons/ri";
import { IoPersonSharp } from "react-icons/io5";
import { IoIosPerson, IoIosLogOut } from "react-icons/io";

const actions = [
    { icon: <IoIosPerson />, name: "Edit Profile", to: "/edit-profile" },
    { icon: <FaRegHeart />, name: "Wish List", to: "/wish-list" },
    { icon: <RiBillLine />, name: "Order List", to: "/order-list" },
];

export default function ProfileSpeed() {
    const navigate = useNavigate();

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
        <Box sx={{ position: "relative", zIndex: 10 }}>
            <SpeedDial
                ariaLabel="Profile actions"
                sx={{
                    position: "fixed",
                    bottom: 25,
                    left: 25,
                    "& .MuiFab-primary": {
                        backgroundColor: "#262626FF",
                        color: "#fff",
                    },
                    "& .MuiSpeedDialAction-fab": {
                        backgroundColor: "#262626AE",
                        color: "#fff",
                        "&:hover": {
                            backgroundColor: "#333333",
                        },
                    },
                    "& .MuiSpeedDialAction-staticTooltipLabel": {
                        backgroundColor: "#444",
                        color: "#fff",
                    },
                }}
                icon={<IoPersonSharp />}
            >
                {actions.map((action) => (
                    <SpeedDialAction
                        key={action.name}
                        icon={
                            <Link to={action.to} style={{ color: "inherit", textDecoration: "none" }}>
                                {action.icon}
                            </Link>
                        }
                        tooltipTitle={action.name}
                    />
                ))}
                <SpeedDialAction
                    key="Log Out"
                    icon={<IoIosLogOut />}
                    tooltipTitle="Log Out"
                    onClick={handleLogout}
                />
            </SpeedDial>
        </Box>
    );
}
