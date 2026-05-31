!macro clearRmikLegacyUserData
    RMDir /r "$APPDATA\sehat-indonesiaku"
    RMDir /r "$APPDATA\RMIK Medical Record"
    RMDir /r "$LOCALAPPDATA\sehat-indonesiaku"
    RMDir /r "$LOCALAPPDATA\RMIK Medical Record"
!macroend

!macro clearRmikActiveUserData
    RMDir /r "$APPDATA\RMIK"
    RMDir /r "$APPDATA\rmik-medical-record"
    RMDir /r "$LOCALAPPDATA\RMIK"
    RMDir /r "$LOCALAPPDATA\rmik-medical-record"
!macroend

!macro customInstall
    !insertmacro clearRmikLegacyUserData
!macroend

!macro customUnInstall
    !insertmacro clearRmikLegacyUserData
    !insertmacro clearRmikActiveUserData
!macroend
