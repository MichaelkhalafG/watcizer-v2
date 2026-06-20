import { useContext } from 'react'
import { useUIStore } from '../../../Store/uiStore'
import Box from '@mui/material/Box'
import useMediaQuery from '@mui/material/useMediaQuery'
import { Link, useNavigate } from 'react-router-dom'
import SpeedDial from '@mui/material/SpeedDial'
import SpeedDialAction from '@mui/material/SpeedDialAction'
import { FaRegHeart, FaLanguage } from 'react-icons/fa'
import { RiBillLine } from 'react-icons/ri'
import { IoPersonSharp } from 'react-icons/io5'
import { IoIosPerson, IoIosLogOut } from 'react-icons/io'
import logo from '../../../assets/images/logo.webp'
import http from '../../../Context/api'
export default function ProfileSpeed() {
  const { language, setLanguage } = useUIStore()
  const navigate = useNavigate()
  const isDesktop = useMediaQuery('(min-width:768px)')
  function toggleLang() {
    setLanguage(language === 'ar' ? 'en' : 'ar')
  }
  // Desktop shows English labels; mobile is localized + adds a language toggle.
  const actions = [
    {
      icon: <IoIosPerson />,
      name: isDesktop ? 'Edit Profile' : language === 'ar' ? 'تعديل الملف الشخصي' : 'Edit Profile',
      to: '/edit-profile',
    },
    {
      icon: <FaRegHeart />,
      name: isDesktop ? 'Wish List' : language === 'ar' ? 'قائمة الأمنيات' : 'Wish List',
      to: '/wish-list',
    },
    {
      icon: <RiBillLine />,
      name: isDesktop ? 'Order List' : language === 'ar' ? 'قائمة الطلبات' : 'Order List',
      to: '/order-list',
    },
    // Language toggle is a mobile-only action (desktop has the header switcher).
    ...(isDesktop
      ? []
      : [
          {
            icon: <FaLanguage />,
            name: language === 'ar' ? 'تغيير اللغة' : 'Language',
            onClick: toggleLang,
          },
        ]),
  ]
  const handleLogout = async () => {
    const token = sessionStorage.getItem('token')
    try {
      await http.post(`/logout?token=${token}`)
      sessionStorage.clear()
      localStorage.clear()
      navigate('/')
      window.location.reload()
    } catch (error) {
      if (error === 'Token has expired') {
        sessionStorage.clear()
        localStorage.clear()
        navigate('/')
        window.location.reload()
      }
    }
  }
  return (
    <>
      {/* Mobile-only brand logo (top-left) */}
      {!isDesktop && (
        <div className="col-12 p-3 d-flex" style={{ position: 'relative', zIndex: 1000 }}>
          <Link to="/" style={{ textDecoration: 'none' }}>
            <img
              src={logo}
              alt="logo"
              className="logo"
              style={{ height: '50px', maxWidth: '150px', cursor: 'pointer' }}
            />
          </Link>
        </div>
      )}
      <Box sx={{ position: 'relative', zIndex: 10 }}>
        <SpeedDial
          ariaLabel="Profile actions"
          sx={
            isDesktop
              ? {
                  position: 'fixed',
                  bottom: 25,
                  left: 25,
                  '& .MuiFab-primary': {
                    backgroundColor: '#262626FF',
                    color: '#fff',
                  },
                  '& .MuiSpeedDialAction-fab': {
                    backgroundColor: '#262626AE',
                    color: '#fff',
                    '&:hover': {
                      backgroundColor: '#333333',
                    },
                  },
                  '& .MuiSpeedDialAction-staticTooltipLabel': {
                    backgroundColor: '#444',
                    color: '#fff',
                  },
                }
              : {
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
                }
          }
          direction={isDesktop ? 'up' : 'down'}
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
              tooltipOpen={!isDesktop}
            />
          ))}
          <SpeedDialAction
            key="Log Out"
            icon={<IoIosLogOut />}
            tooltipTitle="Log Out"
            onClick={handleLogout}
            tooltipOpen={!isDesktop}
          />
        </SpeedDial>
      </Box>
    </>
  )
}
