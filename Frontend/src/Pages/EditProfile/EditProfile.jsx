import { useState, useEffect, useCallback, useContext } from "react";
import PropTypes from "prop-types";
import {
    Tabs, Tab, TextField, Button, CircularProgress, MenuItem, InputAdornment,
    Select, InputLabel, FormControl, IconButton, Alert, Snackbar, Avatar
} from "@mui/material";
import { CloudUpload } from "@mui/icons-material";
import DOMPurify from "dompurify";
import { MyContext } from "../../Context/Context";
import { Visibility, VisibilityOff } from "@mui/icons-material";
import axios from "axios";

function CustomTabPanel(props) {
    const { children, value, index, ...other } = props;
    return (
        <div
            role="tabpanel"
            hidden={value !== index}
            id={`simple-tabpanel-${index}`}
            aria-labelledby={`simple-tab-${index}`}
            {...other}
        >
            {value === index && <div className="p-3">{children}</div>}
        </div>
    );
}

CustomTabPanel.propTypes = {
    children: PropTypes.node,
    index: PropTypes.number.isRequired,
    value: PropTypes.number.isRequired,
};

function EditProfile() {
    const { language, user_id, windowWidth, shippingid, shippingPrices, setShipping, setShippingName, setShippingid } = useContext(MyContext);

    const [value, setValue] = useState(0);
    const [addresses, setAddresses] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [alertMessage, setAlertMessage] = useState("");
    const [alertType, setAlertType] = useState("info");
    const [openAlert, setOpenAlert] = useState(false);
    const [streetName, setStreetName] = useState("");
    const [buildingNumber, setBuildingNumber] = useState("");
    const [floorNumber, setFloorNumber] = useState("");
    const [apartmentNumber, setApartmentNumber] = useState("");
    const [firstName, setFirstName] = useState(sessionStorage.getItem("first_name"));
    const [lastName, setLastName] = useState(sessionStorage.getItem("last_name"));
    const [phoneNumber, setPhoneNumber] = useState(sessionStorage.getItem("phone_number") !== "null" ? sessionStorage.getItem("phone_number") : "");
    const [currentPassword, setCurrentPassword] = useState("");
    const [newPassword, setNewPassword] = useState("");
    const [confirmPassword, setConfirmPassword] = useState("");
    const [showPassword, setShowPassword] = useState({
        current: false,
        new: false,
        confirm: false,
    });
    const [previewOpen, setPreviewOpen] = useState(false);
    const [previewUrl, setPreviewUrl] = useState("");
    const [image, setImage] = useState(sessionStorage.getItem("image") !== "null" ? sessionStorage.getItem("image") : "");
    const showAlert = (message, type) => {
        setAlertMessage(message);
        setAlertType(type);
        setOpenAlert(true);
    };

    const apiCode = "NbmFylY0vcwnhxUrm1udMgcX1MtPYb4QWXy1EKqVenm6uskufcXKeHh5W4TM5Iv0";

    const fetchAddresses = useCallback(() => {
        setLoading(true);
        axios
            .get("https://dash.watchizereg.com/api/show_address", {
                headers: { "Api-Code": apiCode },
            })
            .then((response) => {
                if (response.data) {
                    const filteredAddresses = response.data.filter(
                        (address) => parseInt(address.user_id) === parseInt(user_id)
                    );
                    setAddresses(filteredAddresses);
                } else {
                    setAddresses([]);
                }
            })
            .catch(() => {
                setError(language === "ar" ? "فشل تحميل العناوين" : "Failed to load addresses.");
            })
            .finally(() => setLoading(false));
    }, [user_id, language]);

    useEffect(() => {
        fetchAddresses();
    }, [fetchAddresses]);

    useEffect(() => {
        if (!image || typeof image === "string") {
            setPreviewUrl(image || "");
            return;
        }

        const objectUrl = URL.createObjectURL(image);
        setPreviewUrl(objectUrl);

        return () => URL.revokeObjectURL(objectUrl);
    }, [image]);

    const handleChange = (event, newValue) => setValue(newValue);

    const handleAddAddress = () => {
        if (!shippingid) {
            showAlert(language === "ar" ? "الرجاء اختيار مدينة الشحن أولاً" : "Please select a shipping city first", "warning");
            return;
        }
        if (!streetName.trim() || !buildingNumber.trim()) {
            showAlert(language === "ar" ? "يرجى إدخال اسم الشارع ورقم المبنى." : "Please enter the street name and building number.", "warning");
            return;
        }
        if (!phoneNumber.trim()) {
            showAlert(language === "ar" ? "يرجى إدخال رقم الهاتف." : "Please enter a phone number.", "warning");
            return;
        }

        let fullAddress = `${streetName}, Building ${buildingNumber}`;
        if (floorNumber.trim()) fullAddress += `, Floor ${floorNumber}`;
        if (apartmentNumber.trim()) fullAddress += `, Apartment ${apartmentNumber}`;

        const payload = {
            user_id,
            shipping_city_id: shippingid,
            address_line: fullAddress,
            phone_number_one: phoneNumber
        };

        axios.post("https://dash.watchizereg.com/api/add_address", payload, {
            headers: { "Api-Code": apiCode }
        })
            .then(response => {
                if (response.data.success) {
                    showAlert(language === "ar" ? "تم إضافة العنوان بنجاح!" : "Address added successfully!", "success");
                    fetchAddresses();
                    setStreetName("");
                    setBuildingNumber("");
                    setFloorNumber("");
                    setApartmentNumber("");
                    setPhoneNumber("");
                } else {
                    showAlert(language === "ar" ? "فشل إضافة العنوان." : "Failed to add address.", "error");
                }
            })
        // .catch(error => console.error("Error adding address:", error));
    };
    const handleChangeShipping = (event) => {
        const selectedId = event.target.value;
        setShippingid(selectedId);

        const selectedShipping = shippingPrices.find(city => city.id === selectedId);
        if (selectedShipping) {
            setShipping(selectedShipping.Price.toString());
            setShippingName(language === 'ar' ? selectedShipping.GovernorateAr : selectedShipping.GovernorateEn);
        }
    };
    const handleUpdateProfile = () => {
        setLoading(true);
        const formData = new FormData();
        formData.append("id", user_id);
        formData.append("first_name", DOMPurify.sanitize(firstName));
        formData.append("last_name", DOMPurify.sanitize(lastName));
        formData.append("phone_number", DOMPurify.sanitize(phoneNumber));
        if (image) formData.append("image", image);

        axios.post("https://dash.watchizereg.com/api/updateProfile", formData, {
            headers: { "Api-Code": apiCode },
        })
            .then(async () => {
                showAlert("Profile updated successfully!", "success");
                sessionStorage.setItem("first_name", firstName);
                sessionStorage.setItem("last_name", lastName);
                sessionStorage.setItem("phone_number", phoneNumber);
                if (image) {
                    try {
                        const response = await axios.get("https://dash.watchizereg.com/api/all_user", {
                            headers: {
                                "Api-Code": "NbmFylY0vcwnhxUrm1udMgcX1MtPYb4QWXy1EKqVenm6uskufcXKeHh5W4TM5Iv0"
                            }
                        });
                        let user_image = response.data.find(user => user.id === user_id)?.image;
                        // console.log("User Image:", user_image);
                        sessionStorage.setItem("image",
                            user_image ? `https://dash.watchizereg.com/Uploads_Images/User/${user_image}` : null
                        );
                        window.location.reload();
                    } catch {
                        // console.error("Error fetching users data", error);
                    }
                }
            })
            .catch(() => showAlert("Failed to update profile.", "error"))
            .finally(() => setLoading(false));
    };

    const togglePasswordVisibility = (field) => {
        setShowPassword((prev) => ({ ...prev, [field]: !prev[field] }));
    };

    const sanitizeInput = (input) => DOMPurify.sanitize(input);

    const handleChangePassword = () => {
        if (!newPassword || newPassword.length < 6) {
            showAlert("New password must be at least 6 characters long.", "error");
            return;
        }
        if (newPassword !== confirmPassword) {
            showAlert("Passwords do not match.", "error");
            return;
        }

        setLoading(true);
        axios.post(
            `https://dash.watchizereg.com/api/updatePassword`,
            null,
            {
                params: {
                    id: user_id,
                    current_password: sanitizeInput(currentPassword),
                    new_password: sanitizeInput(newPassword),
                    new_password_confirmation: sanitizeInput(confirmPassword),
                },
                headers: { "Api-Code": apiCode },
            }
        )
            .then(() => showAlert("Password changed successfully!", "success"))
            .catch(() => showAlert("Failed to change password.", "error"))
            .finally(() => setLoading(false));
    };


    return (
        <div className={`container mt-md-4 mb-md-0 mb-5 mt-0 ${language === "ar" ? "text-right" : "text-left"}`} dir={language === "ar" ? "rtl" : "ltr"}>
            <Tabs value={value} onChange={handleChange} className="d-flex justify-content-center">
                <Tab className="col-4" label={language === "ar" ? "تعديل الملف الشخصي" : "Edit Profile"} />
                <Tab className="col-4" label={language === "ar" ? "تغيير كلمة المرور" : "Change Password"} />
                <Tab className="col-4" label={language === "ar" ? "تعديل العناوين" : "Edit Address"} />
            </Tabs>
            <CustomTabPanel value={value} index={0}>
                <div className="row mb-md-0 mb-5">
                    <div className="col-md-5 col-12 text-center position-relative">
                        <IconButton onClick={() => setImage(null)}>
                            <Avatar
                                src={previewUrl}
                                sx={{ width: 200, height: 200, margin: "auto", cursor: "pointer" }}
                            />
                        </IconButton>
                        <Button
                            variant="contained"
                            component="label"
                            sx={{
                                position: "absolute",
                                bottom: "10px",
                                left: "50%",
                                transform: "translateX(-50%)",
                                backgroundColor: "rgba(0, 0, 0, 0.7)",
                                color: "white"
                            }}
                        >
                            <CloudUpload /> Upload Image
                            <input type="file" hidden onChange={(e) => {
                                if (e.target.files.length > 0) {
                                    setImage(e.target.files[0]);
                                }
                            }} />
                        </Button>
                    </div>
                    <div className="col-md-7 mb-md-0 mb-5 col-12">
                        <TextField fullWidth label="First Name" value={firstName} onChange={(e) => setFirstName(e.target.value)} className="my-3" />
                        <TextField fullWidth label="Last Name" value={lastName} onChange={(e) => setLastName(e.target.value)} className="mb-3" />
                        <TextField fullWidth label="Phone Number" value={phoneNumber} onChange={(e) => setPhoneNumber(e.target.value)} className="mb-3" />
                        <Button variant="contained" color="primary" fullWidth onClick={handleUpdateProfile} disabled={loading}>
                            {loading ? <CircularProgress size={24} /> : "Save"}
                        </Button>
                    </div>
                    {previewOpen && image && (
                        <div onClick={() => setPreviewOpen(false)} style={{
                            position: "fixed", top: 0, left: 0, width: "100%", height: "100%",
                            background: "rgba(0,0,0,0.8)", display: "flex", alignItems: "center", justifyContent: "center",
                            cursor: "pointer",
                            zIndex: 1000
                        }}>
                            <img src={URL.createObjectURL(image)} alt="Preview" style={{ maxWidth: "90%", maxHeight: "90%" }} />
                        </div>
                    )}
                </div>
            </CustomTabPanel>

            <CustomTabPanel value={value} index={1}>
                <form className="mb-md-0 mb-5">
                    <TextField
                        fullWidth
                        label="Current Password"
                        type={showPassword.current ? "text" : "password"}
                        value={currentPassword}
                        onChange={(e) => setCurrentPassword(e.target.value)}
                        className="mb-3"
                        InputProps={{
                            endAdornment: (
                                <InputAdornment position="end">
                                    <IconButton onClick={() => togglePasswordVisibility("current")}>
                                        {showPassword.current ? <VisibilityOff /> : <Visibility />}
                                    </IconButton>
                                </InputAdornment>
                            ),
                        }}
                    />
                    <TextField
                        fullWidth
                        label="New Password"
                        type={showPassword.new ? "text" : "password"}
                        value={newPassword}
                        onChange={(e) => setNewPassword(e.target.value)}
                        className="mb-3"
                        InputProps={{
                            endAdornment: (
                                <InputAdornment position="end">
                                    <IconButton onClick={() => togglePasswordVisibility("new")}>
                                        {showPassword.new ? <VisibilityOff /> : <Visibility />}
                                    </IconButton>
                                </InputAdornment>
                            ),
                        }}
                    />
                    <TextField
                        fullWidth
                        label="Confirm Password"
                        type={showPassword.confirm ? "text" : "password"}
                        value={confirmPassword}
                        onChange={(e) => setConfirmPassword(e.target.value)}
                        className="mb-3"
                        InputProps={{
                            endAdornment: (
                                <InputAdornment position="end">
                                    <IconButton onClick={() => togglePasswordVisibility("confirm")}>
                                        {showPassword.confirm ? <VisibilityOff /> : <Visibility />}
                                    </IconButton>
                                </InputAdornment>
                            ),
                        }}
                    />
                    <Button variant="contained" color="primary" fullWidth onClick={handleChangePassword} disabled={loading}>
                        {loading ? <CircularProgress size={24} /> : "Change Password"}
                    </Button>
                </form>
            </CustomTabPanel>

            <CustomTabPanel value={value} index={2}>
                <h5 className="my-2">{language === "ar" ? "إضافة عنوان جديد" : "Add New Address"}</h5>
                <div className="row border-bottom border-2 mb-md-0 mb-5 py-3">
                    <div className="col-md-6 col-12">
                        <TextField
                            fullWidth
                            label={language === "ar" ? "اسم الشارع" : "Street Name"}
                            value={streetName}
                            onChange={(e) => setStreetName(e.target.value)}
                            className="mb-3"
                        />
                    </div>
                    <div className="col-md-6 col-12">
                        <TextField
                            fullWidth
                            label={language === "ar" ? "رقم المبنى" : "Building Number"}
                            value={buildingNumber}
                            onChange={(e) => setBuildingNumber(e.target.value)}
                            className="mb-3"
                        />
                    </div>
                    <div className="col-md-6 col-12">
                        <TextField
                            fullWidth
                            label={language === "ar" ? "رقم الطابق" : "Floor Number"}
                            value={floorNumber}
                            onChange={(e) => setFloorNumber(e.target.value)}
                            className="mb-3"
                        />
                    </div>
                    <div className="col-md-6 col-12">
                        <TextField
                            fullWidth
                            label={language === "ar" ? "رقم الشقة" : "Apartment Number"}
                            value={apartmentNumber}
                            onChange={(e) => setApartmentNumber(e.target.value)}
                            className="mb-3"
                        />
                    </div>
                    <div className="col-md-6 col-12">
                        <TextField
                            fullWidth
                            label={language === "ar" ? "رقم الهاتف" : "Phone Number"}
                            value={phoneNumber}
                            onChange={(e) => setPhoneNumber(e.target.value)}
                            className="mb-3"
                        />
                    </div>
                    <div className="col-md-6 col-12">
                        <FormControl fullWidth>
                            <InputLabel> {language === "ar" ? "مدينة الشحن" : "Shipping City"} </InputLabel>
                            <Select value={shippingid || ''} onChange={handleChangeShipping} fullWidth>
                                {shippingPrices.map(city => (
                                    <MenuItem key={city.id} value={city.id.toString()}>
                                        {language === "ar" ? city.GovernorateAr : city.GovernorateEn}
                                    </MenuItem>
                                ))}
                            </Select>
                        </FormControl>
                    </div>
                    <Button variant="contained" className="my-2" color="primary" fullWidth onClick={handleAddAddress}>
                        {language === "ar" ? "حفظ العنوان" : "Save Address"}
                    </Button>
                </div>

                {loading ? (
                    <CircularProgress />
                ) : error ? (
                    <p className="text-danger">{error}</p>
                ) : addresses.length === 0 ? (
                    <p>{language === "ar" ? "لا توجد عناوين محفوظة." : "No saved addresses."}</p>
                ) : (
                    <>
                        <h5 className="mt-4">{language === "ar" ? "عناوينك" : "Your Addresses"}</h5>
                        <div className="row py-3 pb-5">
                            {addresses.map((address, index) => (
                                <div key={index} className="col-md-6 col-12 mb-3">
                                    <div className="card p-3 shadow-sm">
                                        <p className="mb-0"><strong>{language === "ar" ? "العنوان:" : "Address:"}</strong> {address.address_line}</p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </>
                )}

            </CustomTabPanel>
            <Snackbar open={openAlert} autoHideDuration={3000} onClose={() => setOpenAlert(false)}
                anchorOrigin={{ vertical: windowWidth >= 768 ? "bottom" : "top", horizontal: windowWidth >= 768 ? "right" : "left" }}
            >
                <Alert severity={alertType} onClose={() => setOpenAlert(false)}>
                    {alertMessage}
                </Alert>
            </Snackbar>
        </div>
    );
}

export default EditProfile;
